<?php

namespace App\Enums;

enum LinkKind: string
{
    case PullRequest = 'pr';
    case Branch = 'branch';
    case Design = 'design';
    case Url = 'url';
}
