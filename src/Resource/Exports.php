<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Resource;

use CoyshDigital\Beacon\Http\Request;
use CoyshDigital\Beacon\Http\Response;
use CoyshDigital\Beacon\Http\Transport;

/**
 * CSV exports, triggered from a saved export template in Beacon.
 *
 * Beacon describes these endpoints as early access and does not list them in
 * its main developer documentation, so treat them as more likely to change than
 * the entity endpoints.
 *
 * The flow is: trigger an export, poll its status until `finished`, then
 * download from the signed URL. That URL expires after an hour, though the
 * underlying data is kept for seven days — poll again for a fresh one.
 */
final class Exports
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINISHED = 'finished';

    public function __construct(private readonly Transport $transport)
    {
    }

    public function trigger(int|string $templateId): Response
    {
        return $this->transport->send(self::triggerRequest($templateId));
    }

    public static function triggerRequest(int|string $templateId): Request
    {
        return new Request('POST', 'entity_export/trigger', body: [
            'entity_export_template_id' => is_numeric($templateId) ? (int)$templateId : $templateId,
        ]);
    }

    /**
     * Progress for one export: its `status`, a `progress` percentage, and a
     * short-lived download URL once it has finished.
     */
    public function status(int|string $exportId): Response
    {
        return $this->transport->send(self::statusRequest($exportId));
    }

    public static function statusRequest(int|string $exportId): Request
    {
        return new Request('GET', 'entity_exports', ['entity_export_id' => (string)$exportId]);
    }
}
