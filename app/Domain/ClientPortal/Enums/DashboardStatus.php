<?php

namespace App\Domain\ClientPortal\Enums;

enum DashboardStatus: string
{
    case Ready = 'ready';
    case Provisioning = 'provisioning';
    case AttentionRequired = 'attention_required';
}