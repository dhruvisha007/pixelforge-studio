import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface Credits {
    balance: string;
    reserved: string;
    available: string;
    available_nanodollars: number;
    reserved_nanodollars: number;
}

export interface SharedData {
    name: string;
    auth: Auth;
    credits: Credits | null;
    flash: { success: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Generation {
    id: string;
    status: GenerationStatus;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimate: { min: string; max: string };
    charge: { display: string; exact: string } | null;
    tokens: { input_text: number; input_image: number; output: number; reasoning: number } | null;
    image: { url: string | null; expired: boolean } | null;
    error: { code: string; message: string } | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
}

export interface Paginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
}

export interface GenerateOptions {
    sizes: string[];
    qualities: string[];
    max_prompt_length: number;
    max_reference_images: number;
}

export interface Estimate {
    min: string;
    max: string;
    min_nanodollars: number;
    max_nanodollars: number;
    tokens: Record<'min' | 'max', Record<string, number>>;
    rates: Record<string, string>;
}
