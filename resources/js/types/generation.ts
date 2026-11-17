import type { Tokens } from '@/lib/money';

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_cost: string;
    max_cost: string;
    cost: string | null;
    usage: (Tokens & { total_tokens: number }) | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    submit_attempts: number;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

export const isFinal = (g: Generation) => g.status === 'succeeded' || g.status === 'failed';
