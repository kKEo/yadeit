<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Feedback;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/**
 * The decisions that can be taken on a piece of feedback. Linking feedback
 * to a work item raises that item's demand; taking the link away lowers it.
 */
class FeedbackTriage
{
    public function __construct(private WorkItemWriter $writer) {}

    /**
     * Turns the feedback into work: a new item, or one more voice for an existing item.
     *
     * @param  array<string, mixed>  $options  item (key of an existing item), or title / kind / priority for a new one
     */
    public function accept(Feedback $feedback, User $actor, array $options = []): Feedback
    {
        return DB::transaction(function () use ($feedback, $actor, $options): Feedback {
            $feedback = $this->lock($feedback);

            if (isset($options['item'])) {
                $item = WorkItem::findByKey((string) $options['item'])
                    ?? throw new ApiException(422, 'validation_failed', 'Unknown item. Pass the key of an existing item, such as WEB-12, or leave it out to create a new one.');
            } else {
                $item = $this->writer->create($feedback->project, [
                    'title' => $options['title'] ?? $feedback->title,
                    'description' => $this->description($feedback),
                    'kind' => $options['kind'] ?? 'feature',
                    'priority' => $options['priority'] ?? 'none',
                ], $actor);
            }

            return $this->decide($feedback, $actor, Feedback::ACCEPTED, ['work_item_id' => $item->id]);
        });
    }

    public function decline(Feedback $feedback, User $actor, ?string $reason): Feedback
    {
        return DB::transaction(fn () => $this->decide($this->lock($feedback), $actor, Feedback::DECLINED, ['decline_reason' => $reason]));
    }

    /** Says the same as another piece of feedback; if that one is linked to an item, this one counts for it too. */
    public function markDuplicate(Feedback $feedback, User $actor, Feedback $original): Feedback
    {
        return DB::transaction(function () use ($feedback, $actor, $original): Feedback {
            $feedback = $this->lock($feedback);

            if ($original->id === $feedback->id || $original->duplicate_of_id !== null) {
                throw new ApiException(422, 'validation_failed', 'Pick the original piece of feedback: not this one, and not one that is itself marked as a duplicate.');
            }

            return $this->decide($feedback, $actor, Feedback::DUPLICATE, [
                'duplicate_of_id' => $original->id,
                'work_item_id' => $original->work_item_id,
            ]);
        });
    }

    /** Puts the feedback back into the inbox, undoing an earlier decision. */
    public function reopen(Feedback $feedback, User $actor): Feedback
    {
        return DB::transaction(fn () => $this->decide($this->lock($feedback), $actor, Feedback::NEW, [], triaged: false));
    }

    public function snooze(Feedback $feedback, ?string $until): Feedback
    {
        if ($feedback->status !== Feedback::NEW) {
            throw new ApiException(409, 'conflict', 'Only feedback that is still in the inbox can be snoozed.');
        }

        $feedback->update(['snoozed_until' => $until]);

        return $feedback;
    }

    /** @param  array<string, mixed>  $attributes */
    private function decide(Feedback $feedback, User $actor, string $status, array $attributes, bool $triaged = true): Feedback
    {
        $before = $feedback->work_item_id;

        $feedback->forceFill([
            'work_item_id' => null,
            'duplicate_of_id' => null,
            'decline_reason' => null,
            ...$attributes,
            'status' => $status,
            'snoozed_until' => null,
            'triaged_by' => $triaged ? $actor->id : null,
            'triaged_at' => $triaged ? now() : null,
        ])->save();

        foreach (array_unique(array_filter([$before, $feedback->work_item_id])) as $itemId) {
            $this->recount((int) $itemId);
        }

        // Feedback that was merged into this one follows it to its item.
        if ($status !== Feedback::DUPLICATE) {
            $followers = Feedback::query()->where('duplicate_of_id', $feedback->id)->get();

            foreach ($followers as $follower) {
                $old = $follower->work_item_id;
                $follower->update(['work_item_id' => $feedback->work_item_id]);

                foreach (array_unique(array_filter([$old, $feedback->work_item_id])) as $itemId) {
                    $this->recount((int) $itemId);
                }
            }
        }

        return $feedback->refresh();
    }

    /** Demand is counted, never incremented, so it cannot drift. */
    public function recount(int $itemId): void
    {
        WorkItem::withoutGlobalScope('token-projects')->withTrashed()->whereKey($itemId)
            ->update(['demand' => Feedback::withoutGlobalScope('token-projects')->where('work_item_id', $itemId)->count()]);
    }

    private function lock(Feedback $feedback): Feedback
    {
        return Feedback::query()->whereKey($feedback->id)->lockForUpdate()->firstOrFail();
    }

    private function description(Feedback $feedback): string
    {
        $from = $feedback->author ? $feedback->author->name : ($feedback->submitter_name ?? 'someone');

        return trim((string) $feedback->body)."\n\n---\nFrom feedback #{$feedback->id}, sent by {$from}.";
    }
}
