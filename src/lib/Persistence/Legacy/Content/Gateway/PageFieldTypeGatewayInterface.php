<?php

declare(strict_types=1);

namespace MateuszBieniek\EzPlatformDatabaseHealthChecker\Persistence\Legacy\Content\Gateway;

interface PageFieldTypeGatewayInterface
{
    public function countOrphanedPageRelations(): int;

    public function getOrphanedPageRelations(int $limit): array;
}
