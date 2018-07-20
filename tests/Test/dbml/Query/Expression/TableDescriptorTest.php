<?php

namespace ryunosuke\Test\dbml\Query\Expression;

use ryunosuke\dbml\Query\Expression\Expression;
use ryunosuke\dbml\Query\Expression\TableDescriptor;
use ryunosuke\dbml\Query\QueryBuilder;
use ryunosuke\Test\Database;
use function ryunosuke\dbml\array_lookup;
use function ryunosuke\dbml\stdclass;

class TableDescriptorTest extends \ryunosuke\Test\AbstractUnitTestCase
{
    function assertDescriptor($actual, $expected)
    {
        $expected += [
            'table'     => null,
            'alias'     => null,
            'accessor'  => null,
            'joinsign'  => '',
            'jointype'  => null,
            'jointable' => [],
            'scope'     => [],
            'condition' => [],
            'fkeyname'  => null,
            'column'    => [],
            'remaining' => '',
        ];
        $values = [];
        foreach ($expected as $name => $value) {
            $values[$name] = $actual->$name;
        }
        $expected['accessor'] = $expected['alias'] ?: $expected['table'];
        $this->assertEquals($expected, $values);
    }

    function test_split()
    {
        $split = self::forcedCallize(TableDescriptor::class, '_split');

        $this->assertEquals([
            't_table' => ['*'],
        ], $split('t_table', ['*']));

        $this->assertEquals([
            't_parent' => ['id', 'name'],
        ], $split('t_parent.id, name', ['*']));

        $this->assertEquals([
            't_parent P' => ['id', 'name'],
        ], $split('t_parent P.id, P.name', ['*']));

        $this->assertEquals([
            't_parent' => ['*'],
            't_child'  => ['id'],
        ], $split('t_parent, t_child.id', ['*']));

        $this->assertEquals([
            't_parent:fkey' => ['*'],
            '+t_child'      => ['id'],
        ], $split('t_parent:fkey.* + t_child.id', []));

        $this->assertEquals([
            't_parent(P.flg=1) P' => ['*'],
            '+t_child'            => ['id'],
        ], $split('t_parent(P.flg=1) P.* + t_child.id', []));

        $this->assertEquals([
            '+t_article' => ['**'],
        ], $split('+t_article.**', []));

        $this->assertEquals([
            't_parent' => [
                't_child' => ['id'],
            ],
        ], $split('t_parent/t_child.id', []));

        $this->assertException('not supports specify other schema', $split, 'schema.table.column', ['*']);
    }

    /**
     * @dataProvider provideDatabase
     * @param Database $database
     */
    function test_forge($database)
    {
        $this->assertEquals(['table'], array_lookup(TableDescriptor::forge($database, 'table'), 'descriptor'));
        $this->assertEquals(['table1', 'table2'], array_lookup(TableDescriptor::forge($database, 'table1,table2'), 'descriptor'));
        $this->assertEquals(['table1', '+table2'], array_lookup(TableDescriptor::forge($database, 'table1+table2'), 'descriptor'));
        $this->assertEquals(['table1', '+table2'], array_lookup(TableDescriptor::forge($database, [
            'table1'  => [],
            '+table2' => []
        ]), 'descriptor'));
        $this->assertEquals(['table1', '+table2', 't_hoge'], array_lookup(TableDescriptor::forge($database, [
            'table1'  => [],
            '+table2' => [],
            'td'      => new TableDescriptor($database, 't_hoge', []),
        ]), 'descriptor'));
        $this->assertEquals(['table', null], array_lookup(TableDescriptor::forge($database, [
            null,
            'table',
            ['c',],
        ]), 'descriptor'));
    }

    /**
     * @dataProvider provideDatabase
     * @param Database $database
     */
    function test___construct($database)
    {
        // 素
        $this->assertDescriptor(new TableDescriptor($database, 't_table', []), [
            'descriptor' => 't_table',
            'table'      => 't_table',
            'alias'      => null,
            'joinsign'   => '',
            'jointype'   => null,
            'jointable'  => [],
            'scope'      => [],
            'condition'  => [],
            'fkeyname'   => null,
            'column'     => [],
            'key'        => 't_table',
        ]);

        // スコープ
        $this->assertDescriptor(new TableDescriptor($database, 't_table@scope@scope1()@scope2(1, "2,3")', []), [
            'table' => 't_table',
            'alias' => null,
            'scope' => [
                'scope'  => [],
                'scope1' => [],
                'scope2' => ['1', '2,3'],
            ],
            'key'   => 't_table@scope@scope1()@scope2(1, "2,3")',
        ]);

        // CONDITION
        $this->assertDescriptor(new TableDescriptor($database, 't_table[on1=1, on2 = 2]', []), [
            'table'     => 't_table',
            'condition' => ['on1=1', 'on2 = 2'],
            'key'       => 't_table[on1=1, on2 = 2]',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table[on1=1, on2 = 2] T', []), [
            'table'     => 't_table',
            'alias'     => 'T',
            'condition' => ['on1=1', 'on2 = 2'],
            'key'       => 't_table[on1=1, on2 = 2] T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table[on1=1] T', [['on2 = 2']]), [
            'table'     => 't_table',
            'alias'     => 'T',
            'condition' => ['on1=1', 'on2 = 2'],
            'key'       => 't_table[on1=1] T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table{tA: tB,uA: uB}', []), [
            'table'     => 't_table',
            'condition' => [stdclass(['tA' => 'tB', 'uA' => 'uB'])],
            'key'       => 't_table{tA: tB,uA: uB}',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table[cond1, cond2]', []), [
            'table'     => 't_table',
            'condition' => ['cond1', 'cond2'],
            'key'       => 't_table[cond1, cond2]',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table[cond1, cond2] T', []), [
            'table'     => 't_table',
            'alias'     => 'T',
            'condition' => ['cond1', 'cond2'],
            'key'       => 't_table[cond1, cond2] T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_table[cond1, cond2]{id1: id2} T', []), [
            'table'     => 't_table',
            'alias'     => 'T',
            'condition' => ['cond1', 'cond2', stdclass(['id1' => 'id2'])],
            'key'       => 't_table[cond1, cond2]{id1: id2} T',
        ]);

        // FOREIGN
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1:fk_parentchild1', []), [
            'table'      => 'foreign_c1',
            'fkeyname'   => 'fk_parentchild1',
            'fkeysuffix' => ':fk_parentchild1',
            'key'        => 'foreign_c1:fk_parentchild1',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1:fk_parentchild1 T', []), [
            'table'      => 'foreign_c1',
            'alias'      => 'T',
            'fkeyname'   => 'fk_parentchild1',
            'fkeysuffix' => ':fk_parentchild1',
            'key'        => 'foreign_c1:fk_parentchild1 T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1: T', []), [
            'table'      => 'foreign_c1',
            'alias'      => 'T',
            'fkeyname'   => '',
            'fkeysuffix' => '',
            'key'        => 'foreign_c1: T',
        ]);

        // PRIMARY
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_p(1, 2) T', []), [
            'table'     => 'foreign_p',
            'alias'     => 'T',
            'condition' => [
                new Expression('T.id IN (?, ?)', [1, 2]),
            ],
            'key'       => 'foreign_p(1, 2) T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1((1, 2), (3, 4))', []), [
            'table'     => 'foreign_c1',
            'condition' => [
                new Expression('(foreign_c1.id = ? AND foreign_c1.seq = ?) OR (foreign_c1.id = ? AND foreign_c1.seq = ?)', [1, 2, 3, 4]),
            ],
            'key'       => 'foreign_c1((1, 2), (3, 4))',
        ]);

        // ORDER
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1+aid-did', []), [
            'table' => 'foreign_c1',
            'order' => ['aid' => 'ASC', 'did' => 'DESC'],
            'key'   => 'foreign_c1',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1-did+aid AS FC', []), [
            'table' => 'foreign_c1',
            'alias' => 'FC',
            'order' => ['did' => 'DESC', 'aid' => 'ASC'],
            'key'   => 'foreign_c1 FC',
        ]);

        // RANGE
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1#10', []), [
            'table'  => 'foreign_c1',
            'offset' => 10,
            'limit'  => 1,
            'key'    => 'foreign_c1',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1#-20', []), [
            'table'  => 'foreign_c1',
            'offset' => null,
            'limit'  => 20,
            'key'    => 'foreign_c1',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 'foreign_c1#10-20', []), [
            'table'  => 'foreign_c1',
            'offset' => 10,
            'limit'  => 10,
            'key'    => 'foreign_c1',
        ]);

        // 複合
        $expected = [
            'table'     => 't_article',
            'alias'     => 'T',
            'accessor'  => 'T',
            'joinsign'  => '',
            'jointype'  => null,
            'jointable' => [],
            'scope'     => [
                ''       => [],
                'scope1' => [],
                'scope2' => [1, 2],
            ],
            'condition' => [
                new Expression('T.article_id IN (?)', [1]),
                'on1 = 1',
            ],
            'fkeyname'  => 'fkeyname',
            'order'     => ['aid' => 'ASC', 'did' => 'DESC'],
            'offset'    => 10,
            'limit'     => 10,
            'column'    => ['id as ID'],
            'key'       => 't_article(1)@@scope1@scope2(1, 2):fkeyname[on1 = 1] T',
        ];
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1)@@scope1@scope2(1, 2):fkeyname[on1 = 1]+aid-did#10-20 AS T.id as ID', []), $expected);
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1)#10-20@@scope1@scope2(1, 2)[on1 = 1]+aid-did:fkeyname AS T.id as ID', []), $expected);
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1):fkeyname@@scope1@scope2(1, 2)+aid-did#10-20[on1 = 1] AS T.id as ID', []), $expected);
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1):fkeyname[on1 = 1]+aid-did#10-20@@scope1@scope2(1, 2) AS T.id as ID', []), $expected);
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1)+aid-did#10-20[on1 = 1]@@scope1@scope2(1, 2):fkeyname AS T.id as ID', []), $expected);
        $this->assertDescriptor(new TableDescriptor($database, 't_article(1)[on1 = 1]+aid-did#10-20:fkeyname@@scope1@scope2(1, 2) AS T.id as ID', []), $expected);

        // JOIN
        $td = new TableDescriptor($database, '+t_table T', [
            'alias' => '+t_join.id',
        ]);
        $this->assertDescriptor($td->jointable[0], [
            'descriptor' => [],
            'table'      => 't_join',
            'alias'      => null,
            'joinsign'   => '+',
            'jointype'   => 'INNER',
            'jointable'  => [],
            'scope'      => [],
            'condition'  => [],
            'fkeyname'   => null,
            'column'     => ['id'],
            'key'        => '+t_join',
        ]);
        $td = new TableDescriptor($database, '+t_table T', [
            '+t_join.id' => [],
        ]);
        $this->assertDescriptor($td->jointable[0], [
            'descriptor' => [],
            'table'      => 't_join',
            'alias'      => null,
            'joinsign'   => '+',
            'jointype'   => 'INNER',
            'jointable'  => [],
            'scope'      => [],
            'condition'  => [],
            'fkeyname'   => null,
            'column'     => ['id'],
            'key'        => '+t_join',
        ]);
        $td = new TableDescriptor($database, '+t_table T', [
            '+TS.id' => $database->test,
        ]);
        $this->assertDescriptor($td->jointable[0], [
            'table'     => 'TS',
            'alias'     => null,
            'joinsign'  => '+',
            'jointype'  => 'INNER',
            'jointable' => [],
            'scope'     => [],
            'condition' => [],
            'fkeyname'  => null,
            'column'    => ['id'],
            'key'       => '+test TS',
        ]);
        $this->assertEquals('TS', $td->jointable[0]->descriptor->alias());
    }

    /**
     * @dataProvider provideDatabase
     * @param Database $database
     */
    function test___construct_asterisk($database)
    {
        $this->assertDescriptor(new TableDescriptor($database, 't_article.**', []), [
            'table'  => 't_article',
            'column' => [
                '*',
                'Comment' => ['*'],
            ],
        ]);
        $this->assertDescriptor(new TableDescriptor($database, 't_article.**', ['t_comment' => null]), [
            'table'  => 't_article',
            'column' => [
                '*',
                't_comment' => null,
            ],
        ]);

        $this->assertDescriptor(new TableDescriptor($database, 'horizontal1.**', []), [
            'table'  => 'horizontal1',
            'column' => [
                '*',
            ],
        ]);

        $this->assertDescriptor(new TableDescriptor($database, 'foreign_s.**', []), [
            'table'  => 'foreign_s',
            'column' => [
                '*',
                'foreign_sc:fk_sc2' => ['*'],
                'foreign_sc:fk_sc1' => ['*'],
            ],
        ]);
    }

    /**
     * @dataProvider provideDatabase
     * @param Database $database
     */
    function test___construct_misc($database)
    {
        // misc
        $this->assertDescriptor(new TableDescriptor($database, '+t_table T', ['id']), [
            'table'    => 't_table',
            'alias'    => 'T',
            'accessor' => 'T',
            'joinsign' => '+',
            'jointype' => 'INNER',
            'fkeyname' => null,
            'column'   => ['id'],
            'key'      => '+t_table T',
        ]);
        $this->assertDescriptor(new TableDescriptor($database, '+Article', []), [
            'table'    => 't_article',
            'alias'    => 'Article',
            'accessor' => 'Article',
            'joinsign' => '+',
            'jointype' => 'INNER',
            'fkeyname' => null,
            'column'   => [],
            'key'      => '+t_article Article',
        ]);

        // qb
        $td = new TableDescriptor($database, 't_table', $database->select('t_child'));
        $this->assertInstanceOf(QueryBuilder::class, $td->table);
    }

    /**
     * @dataProvider provideDatabase
     * @param Database $database
     */
    function test___get($database)
    {
        $td = new TableDescriptor($database, '+t_table T', ['id']);
        $this->assertSame('T', $td->accessor);
        $this->assertSame(null, $td->fkeyname);
        $this->assertSame([], $td->condition);

        $this->assertException('is undefined', L($td)->hogera);
    }
}