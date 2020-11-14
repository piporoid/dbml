<?php
namespace PHPSTORM_META {

    override(new \ryunosuke\dbml\Entity\Entityable,
        map([
            'id'            => 'int',
            'name'          => 'string',
            'group_id1'     => 'int',
            'group_id2'     => 'int',
            'seq'           => 'int',
            'cid'           => 'int',
            'd2_id'         => 'int',
            's_id1'         => 'int',
            's_id2'         => 'int',
            'ancestor_id'   => 'int',
            'ancestor_name' => 'string',
            'child_id'      => 'int',
            'parent_id'     => 'int',
            'child_name'    => 'string',
            'parent_name'   => 'string',
            'summary'       => 'string',
            'pid'           => 'int',
            'cint'          => 'int',
            'cfloat'        => 'float',
            'cdecimal'      => 'float',
            'cdate'         => \DateTime::class,
            'cdatetime'     => \DateTime::class,
            'cstring'       => 'string',
            'ctext'         => 'string',
            'cbinary'       => 'string',
            'cblob'         => 'string',
            'carray'        => 'array',
            'mainid'        => 'int',
            'subid'         => 'int',
            'uc_s'          => 'string',
            'uc_i'          => 'int',
            'uc1'           => 'string',
            'uc2'           => 'int',
            'category'      => 'string',
            'primary_id'    => 'int',
            'log_date'      => \DateTime::class,
            'data'          => 'string',
            'name1'         => 'string',
            'name2'         => 'string',
            'article_id'    => 'int',
            'title'         => 'string',
            'comment_id'    => 'int',
            'comment'       => 'string',
        ])
    );
    override(new \ryunosuke\Test\Entity\Article,
        map([
            'article_id'    => 'int',
            'title'         => 'string',
            'checks'        => 'array',
            'title2'        => 'int',
            'title3'        => 'int',
            'title4'        => 'int',
            'title5'        => 'int',
            'comment_count' => 'array',
        ])
    );
    override(new \ryunosuke\Test\Entity\Comment,
        map([
            'comment_id' => 'int',
            'article_id' => 'int',
            'comment'    => 'string',
        ])
    );
}