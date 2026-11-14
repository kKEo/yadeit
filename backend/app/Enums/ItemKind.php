<?php

namespace App\Enums;

enum ItemKind: string
{
    case Task = 'task';
    case Bug = 'bug';
    case Feature = 'feature';
    case Design = 'design';
    case Research = 'research';
}
