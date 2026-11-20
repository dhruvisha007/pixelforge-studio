<?php

namespace App\Http\Controllers;

use App\Billing\CostCalculator;
use App\Billing\CostEstimate;
use App\Enums\GenerationStatus;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use App\Pixelforge\PricingRepository;
use App\Pixelforge\PricingUnavailableException;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class GenerationController
{
    public function __construct(
        private readonly PricingRepository $pricing,
        private readonly CostCalculator $costs,
    ) {}

    /** The studio: the generate form and the user's history. */
    public function index(Request $request): Response
    {
        return Inertia::render('Studio', [
            'generations' => GenerationResource::collection(
                $request->user()->generations()->latest('id')->paginate(12)
            ),
            'options' => GenerationRequest::options(),
            'markupPercent' => (int) config('studio.markup_percent'),
        ]);
    }

    /**
     * The live price shown on the form before the user submits. The form calls this
     * (debounced) as the user types, so the browser never does any pricing maths.
     */
    public function estimate(GenerationRequest $request): JsonResponse
    {
        try {
            $pricing = $this->pricing->current();
            $estimate = $this->estimateFor($request, $pricing);
        } catch (PricingUnavailableException) {
            return response()->json(['message' => 'Prices are not available right now. Please try again shortly.'], 503);
        }

        // The rates are sent too, so the form can explain how the price is made up.
        return response()->json($estimate->toArray() + ['rates' => $pricing->rates]);
    }

    /**
     * Saves the generation as "pending" and queues the job that sends it to Pixelforge.
     * We never call Pixelforge inside the request: it can be slow, rate limited or
     * overloaded, and the job knows how to wait and retry.
     */
    public function store(GenerationRequest $request): RedirectResponse
    {
        // Worked out again on the server: never trust a price sent by the browser.
        try {
            $estimate = $this->estimateFor($request, $this->pricing->current());
        } catch (PricingUnavailableException) {
            throw ValidationException::withMessages(['prompt' => 'Prices are not available right now. Please try again shortly.']);
        }

        $generation = DB::transaction(function () use ($request, $estimate) {
            // Lock the user so two quick submissions can't both spend the same credit.
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            // Stops one user filling Pixelforge's 3 shared slots with a long queue of their own.
            if ($user->generations()->inProgress()->count() >= config('studio.max_in_progress_per_user')) {
                throw ValidationException::withMessages(['prompt' => 'You already have '.config('studio.max_in_progress_per_user').' images in progress. Wait for one to finish.']);
            }

            // Hold the most it can cost (the max estimate is saved on the generation and counts
            // as "reserved" until it finishes). The real cost is charged when it succeeds.
            if ($user->availableCredit() < $estimate->maxCharge) {
                throw ValidationException::withMessages(['credits' => 'Not enough credit: this image can cost up to '
                    .Money::format($estimate->maxCharge, rounding: RoundingMode::CEILING)
                    .' and you have '.Money::format(max(0, $user->availableCredit()), rounding: RoundingMode::FLOOR).' available.']);
            }

            return $user->generations()->create([
                'status' => GenerationStatus::Pending,
                'prompt' => $request->validated('prompt'),
                'size' => $request->validated('size'),
                'quality' => $request->validated('quality'),
                'reference_images' => $request->referenceImages(),
                'estimated_min_charge_nanodollars' => $estimate->minCharge,
                'estimated_max_charge_nanodollars' => $estimate->maxCharge,
            ]);
        });

        // Dispatched after the transaction has committed, so the job always finds the row.
        SubmitGeneration::dispatch($generation->id);

        return back();
    }

    /** Our stored copy if we have one, otherwise Pixelforge's signed URL while it still works. */
    public function image(Request $request, Generation $generation): HttpResponse
    {
        // 404 rather than 403, so other users can't even tell the image exists.
        abort_unless($generation->user_id === $request->user()->id || $request->user()->is_admin, 404);
        abort_unless($generation->hasViewableImage(), 404);

        if ($generation->image_path !== null) {
            return Storage::disk(config('studio.image_disk'))->response($generation->image_path, null, [
                'Cache-Control' => 'private, max-age=86400',
            ]);
        }

        // The copy job hasn't finished yet, but Pixelforge's link still works.
        return redirect()->away($generation->image_url);
    }

    /** The price range for what is on the form right now. */
    private function estimateFor(GenerationRequest $request, Pricing $pricing): CostEstimate
    {
        return $this->costs->estimate(
            $pricing,
            (string) $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            count($request->referenceImages()),
        );
    }
}
