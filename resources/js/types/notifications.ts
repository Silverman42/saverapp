export interface InboxNotice {
    id: string;
    title: string;
    summary: string;
    reference: string | null;
    category: 'account' | 'financial' | 'plan';
    importance: 'normal' | 'high';
    mandatory: boolean;
    action_required: boolean;
    visibility: 'current' | 'expired' | 'superseded';
    effective_at: string;
    read_at: string | null;
    read_version: number;
    has_destination: boolean;
}
export interface InboxSync {
    status: 'current';
    scope: string;
    unread_count: number;
    as_of: string;
}
export interface InboxResult extends InboxSync {
    items: InboxNotice[];
    next_cursor: string | null;
    page_token: string;
}
export interface InboxFilters {
    read?: string;
    category?: string;
    search?: string;
    from?: string;
    to?: string;
    action_required?: string;
    status?: string;
    page_size?: number;
    cursor?: string;
}
