<?php

namespace ryunosuke\dbml\Metadata;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use ryunosuke\dbml\Utility\Adhoc;
use function ryunosuke\dbml\array_each;
use function ryunosuke\dbml\array_pickup;
use function ryunosuke\dbml\arrayize;

/**
 * スキーマ情報の収集と保持とキャッシュを行うクラス
 *
 * ### キャッシュ
 *
 * カラム情報や主キー情報の取得のためにスキーマ情報を結構な勢いで漁る。
 * しかし、基本的にはスキーマ情報は自動でキャッシュするので意識はしなくて OK。
 *
 * ### VIEW
 *
 * VIEW は TABLE と同等の存在として扱う。つまり `getTableNames` メソッドの返り値には VIEW も含まれる。
 * VIEW は 外部キーやインデックスこそ張れないが、 SELECT 系なら TABLE と同様の操作ができる。
 * 更新可能 VIEW ならおそらく更新も可能である。
 *
 * ### メタ情報
 *
 * テーブルやカラムコメントには ini 形式でメタ情報を埋め込むことができる。
 * 設定されているメタ情報は `getTableColumnMetadata` メソッドで取得することができる。
 * （ただし、現在のところこのメタ情報を活用している機能は非常に少なく、実質 anywhere のみ）。
 *
 * ```sql
 * CREATE TABLE t_table (
 * c_column INT COMMENT 'これはカラムコメントです
 * anywhere.keyonly = 1
 * anywhere.greedy = 0
 * ;↑はカラムのメタ属性です'
 * )
 * COMMENT='これはテーブルコメントです
 * anywhere.keyonly = 1
 * anywhere.greedy = 0
 * ;↑はテーブルのメタ属性です'
 * ```
 *
 * メタ情報は php の `parse_ini_string` で行われるが、ドット区切りで配列の階層を持つことができる。
 * つまり上記のメタ情報は
 *
 * ```php
 * [
 *     'anywhere' => [
 *         'keyonly' => 1,
 *         'greedy'  => 0,
 *     ],
 * ]
 * ```
 *
 * と解釈される。
 * メタ文字列は可能な限り ini としてパースしようとし、いかなる適当な文字列でも警告は出ない。
 */
class Schema
{
    /** @var AbstractSchemaManager */
    private $schemaManger;

    /** @var CacheProvider */
    private $cache;

    /** @var string[] */
    private $tableNames = [];

    /** @var Table[] */
    private $tables = [];

    /** @var Column[][] */
    private $tableColumns = [];

    /** @var array */
    private $tableColumnMetadata = [];

    /** @var ForeignKeyConstraint[][] */
    private $foreignKeys = [];

    /** @var array */
    private $relations = [];

    /**
     * コンストラクタ
     *
     * @param AbstractSchemaManager $schemaManger スキーママネージャ
     * @param CacheProvider|null $cache キャッシュプロバイダ
     */
    public function __construct(AbstractSchemaManager $schemaManger, $cache)
    {
        $this->schemaManger = $schemaManger;
        $this->cache = $cache;
    }

    private function _cache($id, $provider)
    {
        $data = $this->cache->fetch($id);
        if ($data === false) {
            $data = $provider();
            $this->cache->save($id, $data);
        }

        return $data;
    }

    /**
     * 一切のメタデータを削除する
     */
    public function refresh()
    {
        $this->tableNames = [];
        $this->tables = [];
        $this->tableColumns = [];
        $this->tableColumnMetadata = [];
        $this->foreignKeys = [];
        $this->relations = [];

        $this->cache->flushAll();
    }

    /**
     * テーブルオブジェクトをメタデータに追加する
     *
     * @param Table $table 追加するテーブルオブジェクRと
     */
    public function addTable($table)
    {
        /// 一過性のものを想定しているのでこのメソッドで決してキャッシュ保存を行ってはならない

        $table_name = $table->getName();

        if ($this->hasTable($table_name)) {
            throw SchemaException::tableAlreadyExists($table_name);
        }

        $this->tableNames[] = $table_name;
        $this->tables[$table_name] = $table;
    }

    /**
     * テーブルが存在するなら true を返す
     *
     * @param string $table_name 調べるテーブル名
     * @return bool テーブルが存在するなら true
     */
    public function hasTable($table_name)
    {
        //$tables = array_flip($this->getTableNames());
        //return isset($tables[$table_name]);
        return in_array($table_name, $this->getTableNames(), true);
    }

    /**
     * テーブル名一覧を取得する
     *
     * @return string[] テーブル名配列
     */
    public function getTableNames()
    {
        if (!$this->tableNames) {
            $this->tableNames = $this->_cache('@table_names.pson', function () {
                $table_names = $this->schemaManger->listTableNames();

                $paths = array_fill_keys($this->schemaManger->getSchemaSearchPaths(), '');
                $views = array_each($this->schemaManger->listViews(), function (&$carry, View $view) use ($paths) {
                    $ns = $view->getNamespaceName();
                    if ($ns === null || isset($paths[$ns])) {
                        $carry[] = $view->getShortestName($ns);
                    }
                }, []);

                return array_merge($table_names, $views);
            });
        }
        return $this->tableNames;
    }

    /**
     * テーブルオブジェクトを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return Table テーブルオブジェクト
     */
    public function getTable($table_name)
    {
        if (!isset($this->tables[$table_name])) {
            if (!$this->hasTable($table_name)) {
                throw SchemaException::tableDoesNotExist($table_name);
            }

            $this->tables[$table_name] = $this->_cache("$table_name.pson", function () use ($table_name) {
                return $this->schemaManger->listTableDetails($table_name);
            });
        }
        return $this->tables[$table_name];
    }

    /**
     * テーブルのカラムオブジェクトを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return Column[] テーブルのカラムオブジェクト配列
     */
    public function getTableColumns($table_name)
    {
        if (!isset($this->tableColumns[$table_name])) {
            $this->tableColumns[$table_name] = $this->getTable($table_name)->getColumns();
        }
        return $this->tableColumns[$table_name];
    }

    /**
     * テーブルのコメントからメタデータを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @param string $column_name 取得したいカラム名。省略時は全カラム
     * @return array カラムのメタデータ配列
     */
    public function getTableColumnMetadata($table_name, $column_name = null)
    {
        $tid = $table_name . '.';
        $cid = $tid . $column_name;
        if (!isset($this->tableColumnMetadata[$cid])) {
            $table = $this->getTable($table_name);

            if (!isset($this->tableColumnMetadata[$tid])) {
                $this->tableColumnMetadata[$tid] = $this->_cache("$tid-metaoption.pson", function () use ($table) {
                    return Adhoc::parse_ini($table->hasOption('comment') ? $table->getOption('comment') : '');
                });
            }

            if ($column_name) {
                if (!$table->hasColumn($column_name)) {
                    throw SchemaException::columnDoesNotExist($column_name, $table_name);
                }
                $this->tableColumnMetadata[$cid] = $this->_cache("$cid-metaoption.pson", function () use ($table, $column_name) {
                    return Adhoc::parse_ini($table->getColumn($column_name)->getComment());
                });
            }
        }
        return $this->tableColumnMetadata[$cid];
    }

    /**
     * テーブルの主キーインデックスオブジェクトを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return Index 主キーインデックスオブジェクト
     */
    public function getTablePrimaryKey($table_name)
    {
        return $this->getTable($table_name)->getPrimaryKey();
    }

    /**
     * テーブルの主キーカラムオブジェクトを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return Column[] 主キーカラムオブジェクト配列
     */
    public function getTablePrimaryColumns($table_name)
    {
        $pkey = $this->getTablePrimaryKey($table_name);
        if ($pkey === null) {
            return [];
        }
        return array_pickup($this->getTableColumns($table_name), $pkey->getColumns());
    }

    /**
     * テーブルのオートインクリメントカラムを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return Column オートインクリメントカラムがあるならそのオブジェクト、無いなら null
     */
    public function getTableAutoIncrement($table_name)
    {
        $pcols = $this->getTablePrimaryColumns($table_name);
        foreach ($pcols as $pcol) {
            if ($pcol->getAutoincrement()) {
                return $pcol;
            }
        }

        return null;
    }

    /**
     * テーブルの外部キーオブジェクトを取得する
     *
     * @param string $table_name 取得したいテーブル名
     * @return ForeignKeyConstraint[] テーブルの外部キーオブジェクト配列
     */
    public function getTableForeignKeys($table_name)
    {
        if (!isset($this->foreignKeys[$table_name])) {
            // doctrine が制約名を小文字化してるみたいなのでオリジナルでマップする
            $this->foreignKeys[$table_name] = array_each($this->getTable($table_name)->getForeignKeys(), function (&$fkeys, ForeignKeyConstraint $fkey) {
                $fkeys[$fkey->getName()] = $fkey;
            }, []);
        }
        return $this->foreignKeys[$table_name];
    }

    /**
     * テーブルのカラム型を変更する
     *
     * @param string $table_name 変更したいテーブル名
     * @param string $column_name 変更したいカラム名
     * @param Type|string $type 変更する型
     */
    public function setTableColumnType($table_name, $column_name, $type)
    {
        $columns = $this->getTableColumns($table_name);
        if (!isset($columns[$column_name])) {
            throw new \InvalidArgumentException("$column_name is not defined in $table_name.");
        }

        $columns[$column_name]->setType($type instanceof Type ? $type : Type::getType($type));
        $this->tableColumns[$table_name] = $columns;
    }

    /**
     * テーブル間外部キーオブジェクトを取得する
     *
     * 端的に言えば $from_table から $to_table へ向かう外部キーを取得する。ただし
     *
     * - $from_table の指定がない場合は $to_table へ向かう全ての外部キー
     * - $to_table の指定もない場合は データベース上に存在する全ての外部キー
     *
     * を取得する。
     *
     * @param string|null $to_table 向かうテーブル名（被参照外部キー）
     * @param string|null $from_table 元テーブル名（参照外部キー）
     * @return ForeignKeyConstraint[] 外部キーオブジェクト配列
     */
    public function getForeignKeys($to_table = null, $from_table = null)
    {
        if ($from_table === null) {
            $from_table = $this->getTableNames();
        }

        $result = [];
        foreach (arrayize($from_table) as $from) {
            $fkeys = $this->getTableForeignKeys($from);
            foreach ($fkeys as $fk) {
                if ($to_table === null || $to_table === $fk->getForeignTableName()) {
                    $result[$fk->getName()] = $fk;
                }
            }
        }
        return $result;
    }

    /**
     * 外部キーから関連テーブルを取得する
     *
     * @param string $fkeyname 外部キー名
     * @return array [fromTable => $toTable] の配列
     */
    public function getForeignTable($fkeyname)
    {
        foreach ($this->getTableNames() as $from) {
            $fkeys = $this->getTableForeignKeys($from);
            if (isset($fkeys[$fkeyname])) {
                return [$fkeys[$fkeyname]->getLocalTableName() => $fkeys[$fkeyname]->getForeignTableName()];
            }
        }
        return [];
    }

    /**
     * テーブル間を結ぶ外部キーカラムを取得する
     *
     * @param string $table_name1 テーブル名1
     * @param string $table_name2 テーブル名2
     * @param string $fkeyname 制約名。未指定時は唯一の外部キー（複数ある場合は例外）
     * @param bool $direction キー（$table_name1 -> $table_name2 なら true）の方向が格納される
     * @return array [table1_column => table2_column]
     */
    public function getForeignColumns($table_name1, $table_name2, $fkeyname = null, &$direction = null)
    {
        $direction = null;
        if (!$this->hasTable($table_name1) || !$this->hasTable($table_name2)) {
            return [];
        }

        $fkeys = [];
        $fkeys += $this->getForeignKeys($table_name1, $table_name2);
        $fkeys += $this->getForeignKeys($table_name2, $table_name1);
        $fcount = count($fkeys);

        // 外部キーがなくても中間テーブルを介した関連があるかもしれない
        if ($fcount === 0) {
            $ikeys = $this->getIndirectlyColumns($table_name1, $table_name2);
            if ($ikeys) {
                $direction = false;
                return $ikeys;
            }
            $ikeys = $this->getIndirectlyColumns($table_name2, $table_name1);
            if ($ikeys) {
                $direction = true;
                return array_flip($ikeys);
            }
            return [];
        }

        // キー指定がないなら唯一のものを、あるならそれを取得
        $fkey = null;
        if ($fkeyname === null) {
            if ($fcount >= 2) {
                throw new \UnexpectedValueException('ambiguous foreign keys ' . implode(', ', array_keys($fkeys)) . '.');
            }
            $fkey = reset($fkeys);
        }
        else {
            if (!isset($fkeys[$fkeyname])) {
                throw new \UnexpectedValueException("foreign key '$fkeyname' is not exists between $table_name1<->$table_name2 .");
            }
            $fkey = $fkeys[$fkeyname];
        }

        // 外部キーカラムを順序に応じてセットして返す
        if ($fkey->getForeignTableName() === $table_name1) {
            $direction = false;
            $keys = $fkey->getLocalColumns();
            $vals = $fkey->getForeignColumns();
        }
        else {
            $direction = true;
            $keys = $fkey->getForeignColumns();
            $vals = $fkey->getLocalColumns();
        }
        return array_combine($keys, $vals);
    }

    /**
     * テーブルに外部キーを追加する
     *
     * このメソッドで追加された外部キーはデータベースに反映されるわけでもないし、キャッシュにも乗らない。
     * あくまで「アプリ的にちょっとリレーションが欲しい」といったときに使用する想定。
     *
     * @param ForeignKeyConstraint $fkey 追加する外部キーオブジェクト
     * @return ForeignKeyConstraint 追加した外部キーオブジェクト
     */
    public function addForeignKey($fkey)
    {
        // 引数チェック(LocalTable は必須じゃないので未セットの場合がある)
        if ($fkey->getLocalTable() === null) {
            throw new \InvalidArgumentException('$fkey\'s localTable is not set.');
        }

        $lTable = $fkey->getLocalTableName();
        $fTable = $fkey->getForeignTableName();
        $lCols = $fkey->getLocalColumns();
        $fCols = $fkey->getForeignColumns();

        // カラム存在チェック
        if (count($lCols) !== count(array_pickup($this->getTableColumns($lTable), $lCols))) {
            throw new \InvalidArgumentException("undefined column for $lTable.");
        }
        if (count($fCols) !== count(array_pickup($this->getTableColumns($fTable), $fCols))) {
            throw new \InvalidArgumentException("undefined column for $fTable.");
        }

        // テーブルとカラムが一致するものがあるなら例外
        $fkeys = $this->getTableForeignKeys($lTable);
        foreach ($fkeys as $fname => $fk) {
            if ($fTable === $fk->getForeignTableName()) {
                if ($lCols === $fk->getLocalColumns() && $fCols === $fk->getForeignColumns()) {
                    throw new \UnexpectedValueException('foreign key already defined same.');
                }
            }
        }

        // テーブル自体に追加すると本来の外部キーと混ざってしまって判別が困難になるのでキャッシュのみ追加する
        // $this->getTable()->addForeignKeyConstraint();

        // キャッシュしてそれを返す
        return $this->foreignKeys[$lTable][$fkey->getName()] = $fkey;
    }

    /**
     * テーブルの外部キーを削除する
     *
     * このメソッドで削除された外部キーはデータベースに反映されるわけでもないし、キャッシュにも乗らない。
     * あくまで「アプリ的にちょっとリレーションを外したい」といったときに使用する想定。
     *
     * @param ForeignKeyConstraint|string $fkey 削除する外部キーオブジェクトあるいは外部キー文字列
     * @return ForeignKeyConstraint 削除した外部キーオブジェクト
     */
    public function ignoreForeignKey($fkey)
    {
        // 文字列指定ならオブジェクト化
        if (is_string($fkey)) {
            $all = $this->getForeignKeys();
            if (!isset($all[$fkey])) {
                throw new \InvalidArgumentException("undefined foreign key '$fkey'.");
            }
            $fkey = $all[$fkey];
        }

        // 引数チェック(LocalTable は必須じゃないので未セットの場合がある)
        if ($fkey->getLocalTable() === null) {
            throw new \InvalidArgumentException('$fkey\'s localTable is not set.');
        }

        $lTable = $fkey->getLocalTableName();
        $fTable = $fkey->getForeignTableName();
        $lCols = $fkey->getLocalColumns();
        $fCols = $fkey->getForeignColumns();

        // テーブルとカラムが一致するものを削除
        $deleted = null;
        $fkeys = $this->getTableForeignKeys($lTable);
        foreach ($fkeys as $fname => $fk) {
            if ($fTable === $fk->getForeignTableName()) {
                if ($lCols === $fk->getLocalColumns() && $fCols === $fk->getForeignColumns()) {
                    $deleted = $fkeys[$fname];
                    unset($fkeys[$fname]);
                }
            }
        }

        // 消せなかったら例外
        if (!$deleted) {
            throw new \InvalidArgumentException('matched foreign key is not found.');
        }

        // テーブル自体から削除すると本来の外部キーと混ざってしまって判別が困難になるのでキャッシュのみ削除する
        // $this->getTable()->removeForeignKey();

        // 再キャッシュすれば「なにを無視するか」を覚えておく必要がない
        $this->foreignKeys[$lTable] = $fkeys;

        return $deleted;
    }

    /**
     * 外部キーから [table => [columnA => [table => [column => FK]]]] な配列を生成する
     *
     * 外部キーがループしてると導出が困難なため、木構造ではなく単純なフラット配列にしてある。
     * （自身へアクセスすれば木構造的に辿ることは可能）。
     *
     * @return array [table => [columnA => [table => [column => FK]]]]
     */
    public function getRelation()
    {
        if (!$this->relations) {
            $this->relations = $this->_cache('@relations.pson', function () {
                return array_each($this->getForeignKeys(), function (&$carry, ForeignKeyConstraint $fkey) {
                    $ltable = $fkey->getLocalTableName();
                    $ftable = $fkey->getForeignTableName();
                    $lcolumns = $fkey->getLocalColumns();
                    $fcolumns = $fkey->getForeignColumns();
                    foreach ($fcolumns as $n => $fcolumn) {
                        $carry[$ltable][$lcolumns[$n]][$ftable][$fcolumn] = $fkey->getName();
                    }
                }, []);
            });
        }

        return $this->relations;
    }

    /**
     * 中間テーブルを介さずに結合できるカラムを返す
     *
     * @param string $to_table 向かうテーブル名（被参照外部キー）
     * @param string $from_table 元テーブル名（参照外部キー）
     * @return array [lcolmun => fcolumn]
     */
    public function getIndirectlyColumns($to_table, $from_table)
    {
        $result = [];
        foreach ($this->getTableForeignKeys($from_table) as $fkey) {
            foreach ($fkey->getLocalColumns() as $lcolumn) {
                // 外部キーカラムを一つづつ辿って
                $routes = $this->followColumnName($to_table, $from_table, $lcolumn);

                // 経路は問わず最終的に同じカラムに行き着く（unique して1）なら加える
                $columns = array_unique($routes);
                if (count($columns) === 1) {
                    $result[$lcolumn] = reset($columns);
                }
            }
        }
        return $result;
    }

    /**
     * 外部キーを辿って「テーブルA.カラムX」から「テーブルB.カラムY」を導出
     *
     * 返り値のキーには辿ったパス（テーブル）が / 区切りで格納される。
     *
     * @param string $to_table 向かうテーブル名（被参照外部キー）
     * @param string $from_table 元テーブル名（参照外部キー）
     * @param string $from_column 元カラム名
     * @return array 辿ったパス
     */
    public function followColumnName($to_table, $from_table, $from_column)
    {
        $relations = $this->getRelation();

        $result = [];
        $trace = function ($from_table, $from_column) use (&$trace, &$result, $to_table, $relations) {
            if (!isset($relations[$from_table][$from_column])) {
                return;
            }
            foreach ($relations[$from_table][$from_column] as $p_table => $c_columns) {
                foreach ($c_columns as $cc => $dummy) {
                    if ($p_table === $to_table) {
                        $result[$from_table . '/' . $p_table] = $cc;
                    }
                    $trace($p_table, $cc);
                }
            }
        };
        $trace($from_table, $from_column);
        return $result;
    }
}