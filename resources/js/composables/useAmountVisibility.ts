import type { Ref } from 'vue';
import { ref } from 'vue';

const STORAGE_KEY = 'hide-amounts';
const MASK = '₦ •••••';
const AMOUNT_PATTERN = /[−-]?₦\s?[\d,]+\.\d{2}/g;

const readStoredPreference = (): boolean => {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
};

const amountsHidden = ref<boolean>(
    typeof window === 'undefined' ? false : readStoredPreference(),
);

/** Original text of every text node currently showing a masked amount. */
let originals = new WeakMap<Text, string>();
let maskedNodes = new Set<Text>();
let observer: MutationObserver | null = null;
let isMaskingActive = false;

const maskNode = (node: Text): void => {
    const text = node.nodeValue ?? '';

    if (originals.has(node) && text.includes(MASK)) {
        return;
    }

    AMOUNT_PATTERN.lastIndex = 0;

    if (!AMOUNT_PATTERN.test(text)) {
        return;
    }

    originals.set(node, text);
    maskedNodes.add(node);
    node.nodeValue = text.replace(AMOUNT_PATTERN, MASK);
};

const maskTree = (root: Node): void => {
    if (root.nodeType === Node.TEXT_NODE) {
        maskNode(root as Text);

        return;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);

    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        maskNode(node as Text);
    }
};

const restoreAll = (): void => {
    observer?.disconnect();

    maskedNodes.forEach((node) => {
        const original = originals.get(node);

        if (original !== undefined && node.isConnected) {
            node.nodeValue = original;
        }
    });

    originals = new WeakMap();
    maskedNodes = new Set();
};

const handleMutations = (mutations: MutationRecord[]): void => {
    for (const mutation of mutations) {
        if (mutation.type === 'characterData') {
            const node = mutation.target as Text;

            // The app wrote new text into a masked node: forget the old original.
            if (!(node.nodeValue ?? '').includes(MASK)) {
                originals.delete(node);
            }

            maskNode(node);
            continue;
        }

        mutation.addedNodes.forEach((node) => maskTree(node));
    }

    // Drop references to nodes that left the page.
    maskedNodes.forEach((node) => {
        if (!node.isConnected) {
            maskedNodes.delete(node);
        }
    });
};

/**
 * Masks or reveals every ₦ amount on the page. Form input values are not
 * text nodes, so data entry is never affected.
 */
export function applyAmountMasking(shouldMask: boolean): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.toggle('hide-amounts', shouldMask);

    if (shouldMask === isMaskingActive) {
        return;
    }

    isMaskingActive = shouldMask;

    if (!shouldMask) {
        restoreAll();

        return;
    }

    maskTree(document.body);
    observer ??= new MutationObserver(handleMutations);
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true,
    });
}

export type UseAmountVisibilityReturn = {
    amountsHidden: Ref<boolean>;
    toggleAmounts: () => void;
};

export function useAmountVisibility(): UseAmountVisibilityReturn {
    function toggleAmounts(): void {
        amountsHidden.value = !amountsHidden.value;

        try {
            localStorage.setItem(STORAGE_KEY, amountsHidden.value ? '1' : '0');
        } catch {
            // Storage can be unavailable (private mode); the toggle still works for this visit.
        }
    }

    return { amountsHidden, toggleAmounts };
}
