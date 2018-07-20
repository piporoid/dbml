<?php

namespace ryunosuke\dbml\Generator;

use ryunosuke\dbml\Database;

/**
 * 行ごとに php 配列化する出力クラス
 */
class ArrayGenerator extends AbstractGenerator
{
    public function __construct(array $config = [])
    {
        // なにか特有処理があればここに書く（今のところなし）

        parent::__construct($config);
    }

    protected function initProvider($provider)
    {
        if ($provider instanceof Yielder) {
            $provider->setFetchMethod(Database::METHOD_ARRAY);
        }
    }

    protected function generateHead($resource)
    {
        return fwrite($resource, "<?php return array(\n");
    }

    protected function generateBody($resource, $key, $value, $first_flg)
    {
        return fwrite($resource, var_export($value, true) . ",\n");
    }

    protected function generateTail($resource)
    {
        return fwrite($resource, ");");
    }
}