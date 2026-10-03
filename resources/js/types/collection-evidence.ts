export type CollectionMethod = {
    id: number;
    method_key: 'transfer' | 'pos' | 'other';
    label: string;
    destination_key: string;
    attachment_required: boolean;
};

export type PaymentEvidence = {
    evidence_reference: string;
    customer_id: string;
    customer_name: string;
    collection_method_version_id: number;
    method_key: 'transfer' | 'pos' | 'other';
    method_label: string;
    destination_key: string;
    method_reference: string;
    received_date: string;
    amount_kobo: number;
    source_attestation: string;
    status: 'pending' | 'verified' | 'rejected';
    consumed: boolean;
    review_version: number;
    review_operation_reference: string | null;
    review_reason: string | null;
    files: Array<{ id: number; mime_type: string; byte_size: number }>;
};
