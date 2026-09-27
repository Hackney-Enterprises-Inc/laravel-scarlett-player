<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Requests;

use Closure;
use Hei\ScarlettPlayer\Http\Controllers\ClipController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The player's ClipRange, validated as the plugin sends it: camelCase keys, verbatim.
 *
 * Every message is shown to the viewer in the clip overlay, so they are written for
 * viewers. Validation stops at the first failure so the overlay shows one sentence.
 * Bounds come from config; the client's duration is ignored and re-derived.
 */
class StoreClipRequest extends FormRequest
{
    public const LIVE_MESSAGE = "Clips from live streams aren't available yet.";

    /** The largest time the decimal(10,3) columns hold, in seconds. */
    public const MAX_SECONDS = 9999999.999;

    /** Keys the plugin only fills on live media. Each must be null in v1. */
    public const LIVE_FIELDS = ['seekableStart', 'seekableEnd', 'startDate', 'endDate'];

    protected $stopOnFirstFailure = true;

    /**
     * With clips.enabled off the endpoint answers 404 before validation, so a
     * disabled host never reports validation errors for a request it will not take.
     */
    protected function prepareForValidation(): void
    {
        abort_unless((bool) config('scarlett-player.clips.enabled', true), 404, ClipController::DISABLED_MESSAGE);
    }

    public function authorize(): bool
    {
        // ClipPolicy::create needs the resolved media, so the controller authorizes.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $live = fn (string $attribute, mixed $value, Closure $fail) => $value === false ? null : $fail(self::LIVE_MESSAGE);

        $rules = [
            // Live first: a live range is refused before anything about its numbers.
            'isLive' => ['present', $live],
        ];

        foreach (self::LIVE_FIELDS as $field) {
            $rules[$field] = ['nullable', fn (string $attribute, mixed $value, Closure $fail) => $fail(self::LIVE_MESSAGE)];
        }

        return $rules + [
            'mediaId' => ['required', 'string', 'max:255'],
            'clientRequestId' => ['required', 'string', 'max:255'],
            'startTime' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_SECONDS],
            'endTime' => ['required', 'numeric', 'gt:startTime', 'max:'.self::MAX_SECONDS],
            'duration' => ['nullable', 'numeric'],
            'title' => ['nullable', 'string', 'max:255'],
            'capturedAt' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'isLive.present' => self::LIVE_MESSAGE,
            'mediaId.required' => 'This video cannot be clipped.',
            'mediaId.*' => 'This video cannot be clipped.',
            'clientRequestId.*' => 'Something went wrong creating your clip. Please try again.',
            'startTime.max' => 'That point is past the end of the video.',
            'startTime.*' => 'Choose where your clip starts.',
            'endTime.gt' => 'Your clip has to end after it starts.',
            'endTime.max' => 'That point is past the end of the video.',
            'endTime.*' => 'Choose where your clip ends.',
            'duration.*' => 'Choose where your clip ends.',
            'title.max' => 'Clip titles can be at most :max characters.',
            'title.*' => 'Give your clip a shorter title.',
            'capturedAt.*' => 'Something went wrong creating your clip. Please try again.',
        ];
    }

    /**
     * Bounds are checked on endTime - startTime, never on the client's duration.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $duration = $this->clipDuration();
                $min = (float) config('scarlett-player.clips.min_duration', 5);
                $max = (float) config('scarlett-player.clips.max_duration', 60);

                if ($duration < $min) {
                    $validator->errors()->add('endTime', sprintf('Clips must be at least %s seconds.', $this->number($min)));
                } elseif ($duration > $max) {
                    $validator->errors()->add('endTime', sprintf('Clips can be at most %s seconds.', $this->number($max)));
                }
            },
        ];
    }

    public function startTime(): float
    {
        return (float) $this->input('startTime');
    }

    public function endTime(): float
    {
        return (float) $this->input('endTime');
    }

    /**
     * The clip's length as the server derives it: endTime - startTime.
     */
    public function clipDuration(): float
    {
        return round($this->endTime() - $this->startTime(), 3);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }
}
