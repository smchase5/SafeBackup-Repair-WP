import { useState, useEffect } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Sparkles, Bot, Loader2, Lightbulb } from "lucide-react"

interface AIAnalysisDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
    logSnippet: string
}

export function AIAnalysisDialog({ open, onOpenChange, logSnippet }: AIAnalysisDialogProps) {
    const [loading, setLoading] = useState(false)
    const [result, setResult] = useState<any>(null)

    useEffect(() => {
        if (open && logSnippet) {
            analyze()
        }
    }, [open, logSnippet])

    const analyze = async () => {
        setLoading(true)
        setResult(null)

        // Support both Dashboard (sbwpData) and Recovery Portal (sbwpRecoveryData) contexts
        // @ts-ignore
        const config = window.sbwpData || window.sbwpRecoveryData;

        try {
            const res = await fetch(`${config.restUrl}/ai/analyze`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': config.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ log_snippet: logSnippet })
            })
            const data = await res.json()
            // Fake a little thinking time for effect
            setTimeout(() => {
                setResult(data.analysis)
                setLoading(false)
            }, 1500)
        } catch (e) {
            console.error(e)
            setLoading(false)
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Sparkles className="h-5 w-5 text-indigo-500" />
                        AI Error Analysis
                    </DialogTitle>
                    <DialogDescription>
                        Asking our virtual expert to interpret this error log...
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4">
                    {loading ? (
                        <div className="flex flex-col items-center justify-center py-8 space-y-4">
                            <div className="relative">
                                <Bot className="h-12 w-12 text-indigo-200" />
                                <Loader2 className="h-6 w-6 absolute -bottom-1 -right-1 animate-spin text-indigo-600" />
                            </div>
                            <div className="text-center space-y-1">
                                <p className="font-medium text-indigo-900 dark:text-indigo-100">Analyzing Code...</p>
                                <p className="text-xs text-muted-foreground">Identifying patterns and known conflicts</p>
                            </div>
                        </div>
                    ) : result ? (
                        <div className="space-y-4">
                            <div className="bg-indigo-50 dark:bg-indigo-950/30 p-4 rounded-lg border border-indigo-100 dark:border-indigo-900">
                                <h3 className="font-semibold text-indigo-900 dark:text-indigo-200 mb-1">Summary</h3>
                                <p className="text-sm text-indigo-800 dark:text-indigo-300">{result.summary}</p>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Root Cause</h4>
                                    <p className="text-sm">{result.cause}</p>
                                </div>
                                <div className="space-y-2">
                                    <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Suggested Fix</h4>
                                    <div className="flex items-start gap-2">
                                        <Lightbulb className="h-4 w-4 text-emerald-500 shrink-0 mt-0.5" />
                                        <p className="text-sm">{result.solution}</p>
                                    </div>
                                </div>
                            </div>

                            <div className="pt-4 flex justify-end">
                                <Button onClick={() => onOpenChange(false)}>Close Analysis</Button>
                            </div>
                        </div>
                    ) : (
                        <div className="text-center text-muted-foreground py-4">
                            No analysis available.
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
