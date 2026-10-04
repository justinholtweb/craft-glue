<?php

declare(strict_types=1);

namespace justinholtweb\glue\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use justinholtweb\glue\Plugin;
use Throwable;

/**
 * Rewires a long list of referencing elements out of band.
 *
 * Each rewire is a full element save — content, relations, search index, revision — so a few
 * dozen is a slow response and a few thousand is a timed-out one. Above
 * `rewireThreshold` the work comes here instead.
 *
 * The job is deliberately not transactional. A rewire that fails on element 400 of 900 has still
 * correctly fixed 399 elements, and rolling those back would leave a site with 900 broken
 * relations instead of 501 — so failures are logged per element and the run carries on.
 */
class RewireRelations extends BaseJob
{
    /** @var int[] */
    public array $elementIds = [];

    /** @var int[] */
    public array $retiredIds = [];

    public int $survivorId = 0;

    /**
     * Who asked for the rewire. The job runs with no user, so without this the permission
     * filter applied when it was queued would not hold for anything that changed since.
     */
    public ?int $userId = null;

    public function execute($queue): void
    {
        $rewirer = Plugin::getInstance()->rewirer;
        $total = count($this->elementIds);
        $user = $this->userId !== null ? User::find()->id($this->userId)->status(null)->one() : null;

        // The user was deleted while the job waited. Nobody is left to act for, so nothing runs.
        if ($this->userId !== null && $user === null) {
            Craft::warning(sprintf('Skipping a rewire onto %d: user %d no longer exists.', $this->survivorId, $this->userId), Plugin::LOG_CATEGORY);
            return;
        }

        foreach (array_values($this->elementIds) as $index => $elementId) {
            $this->setProgress($queue, $total > 0 ? ($index + 1) / $total : 1);

            try {
                $rewirer->rewireElement((int)$elementId, $this->retiredIds, $this->survivorId, $user);
            } catch (Throwable $e) {
                Craft::error(sprintf(
                    'Could not rewire element %d onto %d: %s',
                    $elementId,
                    $this->survivorId,
                    $e->getMessage(),
                ), Plugin::LOG_CATEGORY);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('glue', 'Repointing relations at the merged entry');
    }
}
