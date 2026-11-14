<?php

namespace App\Enums;

enum RelationType: string
{
    case Blocks = 'blocks';
    case Relates = 'relates';
    case Duplicate = 'duplicate';
}
