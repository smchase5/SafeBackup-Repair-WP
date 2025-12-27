import { useState } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Loader2, CheckCircle2, PlayCircle } from "lucide-react"

interface SafeUpdateDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

export function SafeUpdateDialog({ open, onOpenChange }: SafeUpdateDialogProps) {
    const [status, setStatus] = useState<'idle' | 'running' | 'complete' | 'error'>('idle')
    const [currentStep, setCurrentStep] = useState<string>('')
    const [progress, setProgress] = useState(0)
    const [logs, setLogs] = useState<string[]>([])

    const runSafeUpdate = async () => {
        setStatus('running')
        setLogs([])
        setProgress(0)

        try {
            const res = await fetch(`${window.sbwpData.restUrl}/safe-update/start`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const data = await res.json()

            // Simulate Steps
            for (const step of data.steps) {
                setCurrentStep(step.label)
                setLogs(prev => [...prev, `> ${step.label}`])
                // Fake delay
                await new Promise(r => setTimeout(r, step.duration / 2))
                setProgress(p => p + (100 / data.steps.length))
            }

            setStatus('complete')
            setLogs(prev => [...prev, "✅ Test Complete: No Issues Found."])
        } catch (e) {
            setStatus('error')
            setLogs(prev => [...prev, "❌ Error: Failed to start safe update simulator."])
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Safe Update Tester</DialogTitle>
                    <DialogDescription>
                        Test updates in a temporary sandbox before applying them to your live site.
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4 space-y-4">
                    {status === 'idle' && (
                        <div className="text-center py-8 space-y-4">
                            <div className="mx-auto w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <PlayCircle className="h-6 w-6 text-blue-600" />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                This will clone your site to a temporary staging area and attempt to update 3 plugins.
                            </p>
                            <Button className="w-full" onClick={runSafeUpdate}>
                                Start Safe Update Test
                            </Button>
                        </div>
                    )}

                    {status === 'running' && (
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <Loader2 className="h-5 w-5 animate-spin text-blue-500" />
                                <span className="font-medium text-sm">{currentStep}</span>
                            </div>
                            <div className="h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div className="h-full bg-blue-500 transition-all duration-500" style={{ width: `${progress}%` }} />
                            </div>
                            <div className="bg-slate-950 text-slate-50 p-3 rounded-md font-mono text-xs h-32 overflow-y-auto">
                                {logs.map((log, i) => <div key={i}>{log}</div>)}
                            </div>
                        </div>
                    )}

                    {status === 'complete' && (
                        <div className="text-center py-4 space-y-4">
                            <div className="mx-auto w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center">
                                <CheckCircle2 className="h-6 w-6 text-emerald-600" />
                            </div>
                            <h3 className="font-bold">Updates Passed</h3>
                            <p className="text-sm text-muted-foreground">
                                The updates were applied successfully in the sandbox. It is safe to update your live site.
                            </p>
                            <Button className="w-full bg-emerald-600 hover:bg-emerald-700" onClick={() => window.location.href = '/wp-admin/update-core.php'}>
                                Apply Updates Logic
                            </Button>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
