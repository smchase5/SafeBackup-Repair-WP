import { useState } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Loader2, Bug, AlertTriangle, CheckCircle2 } from "lucide-react"

interface ConflictScannerDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

export function ConflictScannerDialog({ open, onOpenChange }: ConflictScannerDialogProps) {
    const [status, setStatus] = useState<'idle' | 'scanning' | 'found' | 'clean'>('idle')
    const [progress, setProgress] = useState(0)
    const [scannedCount, setScannedCount] = useState(0)
    const [culprit, setCulprit] = useState<any>(null)

    const startScan = async () => {
        setStatus('scanning')
        setProgress(0)
        setScannedCount(0)

        // Simulate scanning progress
        const interval = setInterval(() => {
            setProgress(p => {
                const next = p + 5
                return next > 90 ? 90 : next
            })
            setScannedCount(c => c + 1)
        }, 200)

        try {
            const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/start`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const data = await res.json()

            clearInterval(interval)
            setProgress(100)

            if (data.culprit) {
                setStatus('found')
                setCulprit(data.culprit)
            } else {
                setStatus('clean')
            }

        } catch (e) {
            clearInterval(interval)
            setStatus('idle') // Reset on error for now
            console.error(e)
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Automated Conflict Scanner</DialogTitle>
                    <DialogDescription>
                        Automatically diagnose plugin conflicts by simulating binary searches.
                    </DialogDescription>
                </DialogHeader>

                <div className="py-6 space-y-6">
                    {status === 'idle' && (
                        <div className="text-center space-y-4">
                            <div className="mx-auto w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center">
                                <Bug className="h-8 w-8 text-orange-600" />
                            </div>
                            <p className="text-muted-foreground">
                                This tool will temporarily simulate deactivating plugins to identify which one is causing errors on your site.
                            </p>
                            <Button size="lg" onClick={startScan} className="bg-orange-600 hover:bg-orange-700">
                                Start Auto-Diagnosis
                            </Button>
                        </div>
                    )}

                    {status === 'scanning' && (
                        <div className="space-y-4 text-center">
                            <Loader2 className="h-12 w-12 animate-spin text-orange-500 mx-auto" />
                            <h3 className="font-semibold text-lg">Scanning Plugins...</h3>
                            <p className="text-sm text-muted-foreground">Checked {scannedCount} plugins so far</p>
                            <div className="h-2 bg-slate-100 rounded-full overflow-hidden w-full">
                                <div className="h-full bg-orange-500 transition-all duration-300" style={{ width: `${progress}%` }} />
                            </div>
                        </div>
                    )}

                    {status === 'found' && culprit && (
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4 space-y-3">
                            <div className="flex items-center gap-3 text-red-800">
                                <AlertTriangle className="h-6 w-6" />
                                <h3 className="font-bold text-lg">Conflict Detected!</h3>
                            </div>
                            <div className="bg-white p-3 rounded border border-red-100">
                                <p className="font-semibold">{culprit.name}</p>
                                <p className="text-xs text-muted-foreground font-mono mt-1">{culprit.file}</p>
                            </div>
                            <p className="text-sm text-red-700">
                                <strong>Issue:</strong> {culprit.reason}
                            </p>
                            <div className="flex gap-2 mt-4">
                                <Button variant="destructive" className="flex-1">Deactivate Plugin</Button>
                                <Button variant="outline" className="flex-1" onClick={() => onOpenChange(false)}>Ignore</Button>
                            </div>
                        </div>
                    )}

                    {status === 'clean' && (
                        <div className="text-center space-y-4">
                            <div className="mx-auto w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center">
                                <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                            </div>
                            <h3 className="font-bold text-lg">No Conflicts Found</h3>
                            <p className="text-muted-foreground">your plugins appear to be working correctly together.</p>
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
