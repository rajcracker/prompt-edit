<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationService;
use App\Services\InsufficientCredits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request, PixelforgeClient $pixelforge): Response
    {
        $pricing = rescue(fn () => $pixelforge->pricing(), null, report: true);

        return Inertia::render('Generate', [
            'pricing' => $pricing ? ['rates' => $pricing['rates'], 'tokens' => $pricing['tokens']] : null,
            'markupPercent' => (int) config('services.pixelforge.markup_percent'),
            'sizes' => StoreGenerationRequest::SIZES,
            'qualities' => StoreGenerationRequest::QUALITIES,
            'recent' => GenerationResource::collection(
                $request->user()->generations()->latest('id')->limit(6)->get()
            ),
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationService $service): RedirectResponse
    {
        try {
            $generation = $service->create($request->user(), $request->validated());
        } catch (InsufficientCredits) {
            return back()->withErrors([
                'credits' => 'Your balance doesn\'t cover the most this image could cost. Buy credits or pick a lower quality or size.',
            ]);
        } catch (PixelforgeException) {
            return back()->withErrors(['prompt' => 'We couldn\'t load Pixelforge\'s pricing, so we can\'t price this yet. Please try again shortly.']);
        }

        try {
            $service->submit($generation);
        } catch (PixelforgeException $e) {
            if (! $e->isRetryable()) {
                return back()->withErrors([
                    in_array($e->param, ['size', 'quality', 'reference_images'], true) ? $e->param : 'prompt' => $e->userMessage(),
                ]);
            }

            SubmitGeneration::dispatch($generation)->delay(SubmitGeneration::delay($e, $generation->submit_attempts));

            return back()->with('success', $e->userMessage().' Your image is queued and we\'ll keep retrying automatically.');
        }

        return back()->with('success', 'Generation started. We reserved up to $'.number_format($generation->max_cost_nanos / 1e9, 4).' and will charge the actual cost when it finishes.');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'status' => $request->query('status'),
        ]);
    }
}
