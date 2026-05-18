<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Linear\LinearAdapter;

AdapterRegistry::register('linear', LinearAdapter::class);
