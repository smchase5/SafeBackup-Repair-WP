import { type ClassValue, clsx } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs))
}

export async function copyToClipboard(text: string): Promise<boolean> {
    if (!navigator.clipboard) {
        return fallbackCopyTextToClipboard(text)
    }
    try {
        await navigator.clipboard.writeText(text)
        return true
    } catch (err) {
        console.error('Async: Could not copy text: ', err)
        return fallbackCopyTextToClipboard(text)
    }
}

function fallbackCopyTextToClipboard(text: string): boolean {
    const textArea = document.createElement("textarea")
    textArea.value = text

    // Avoid scrolling to bottom
    textArea.style.top = "0"
    textArea.style.left = "0"
    textArea.style.position = "fixed"

    document.body.appendChild(textArea)
    textArea.focus()
    textArea.select()

    try {
        const successful = document.execCommand('copy')
        document.body.removeChild(textArea)
        return successful
    } catch (err) {
        console.error('Fallback: Oops, unable to copy', err)
        document.body.removeChild(textArea)
        return false
    }
}
