import { useState, useEffect } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Loader2, CheckCircle2, PlayCircle, XCircle, AlertTriangle, Package, RefreshCw, Sparkles } from "lucide-react"
import {
    getAvailableUpdates,
    createSafeUpdateSession,
    getSafeUpdateSession,
    humanizeReport,
    AvailableUpdate,
    SafeUpdateResult
} from "@/lib/api"

interface SafeUpdateDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

type DialogStatus = 'loading' | 'select' | 'running' | 'complete' | 'failed' | 'error'

export function SafeUpdateDialog({ open, onOpenChange }: SafeUpdateDialogProps) {
    const [status, setStatus] = useState<DialogStatus>('loading')
    const [availablePlugins, setAvailablePlugins] = useState<AvailableUpdate[]>([])
    const [selectedPlugins, setSelectedPlugins] = useState<string[]>([])
    const [sessionId, setSessionId] = useState<number | null>(null)
    const [currentStep, setCurrentStep] = useState<string>('')
    const [progressPercent, setProgressPercent] = useState<number>(0)
    const [progressLogs, setProgressLogs] = useState<string[]>([])
    const [result, setResult] = useState<SafeUpdateResult | null>(null)
    const [error, setError] = useState<string>('')
    const [aiSummary, setAiSummary] = useState<string>('')
    const [loadingAI, setLoadingAI] = useState(false)

    // Load available updates when dialog opens
    useEffect(() => {
        if (open) {
            loadAvailableUpdates()
        } else {
            // Reset state when dialog closes
            setStatus('loading')
            setSelectedPlugins([])
            setSessionId(null)
            setResult(null)
            setAiSummary('')
            setLoadingAI(false)
            setError('')
            setProgressPercent(0)
            setProgressLogs([])
        }
    }, [open])

    // Poll for session status when running
    useEffect(() => {
        let interval: NodeJS.Timeout | null = null

        if (status === 'running' && sessionId) {
            interval = setInterval(async () => {
                try {
                    const session = await getSafeUpdateSession(sessionId)

                    // Update progress from backend
                    if (session.progress) {
                        const newStep = session.progress.message
                        setCurrentStep(newStep)
                        setProgressPercent(session.progress.percent || 0)

                        // Add to logs if it's a new message
                        setProgressLogs(prev => {
                            if (prev.length === 0 || prev[prev.length - 1] !== newStep) {
                                return [...prev, newStep]
                            }
                            return prev
                        })
                    }

                    if (session.status === 'completed') {
                        setResult(session.result)
                        setStatus('complete')
                    } else if (session.status === 'failed') {
                        setError(session.error_message || 'Unknown error')
                        setStatus('failed')
                    }
                } catch (e) {
                    console.error('Polling error:', e)
                }
            }, 1500)
        }

        return () => {
            if (interval) clearInterval(interval)
        }
    }, [status, sessionId])

    const loadAvailableUpdates = async () => {
        setStatus('loading')
        try {
            const updates = await getAvailableUpdates()
            setAvailablePlugins(updates.plugins)
            // Auto-select all by default
            setSelectedPlugins(updates.plugins.map(p => p.file || p.slug || '').filter(Boolean))
            setStatus('select')
        } catch (e) {
            setError('Failed to load available updates')
            setStatus('error')
        }
    }

    const togglePlugin = (file: string) => {
        setSelectedPlugins(prev =>
            prev.includes(file)
                ? prev.filter(p => p !== file)
                : [...prev, file]
        )
    }

    const selectAll = () => {
        setSelectedPlugins(availablePlugins.map(p => p.file || p.slug || '').filter(Boolean))
    }

    const selectNone = () => {
        setSelectedPlugins([])
    }

    const startTest = async () => {
        if (selectedPlugins.length === 0) return

        setStatus('running')
        setCurrentStep('Creating test environment...')

        try {
            const response = await createSafeUpdateSession(selectedPlugins)
            setSessionId(response.session_id)
            setCurrentStep('Test session created, waiting for results...')
        } catch (e) {
            setError('Failed to start safe update test')
            setStatus('error')
        }
    }

    const getStatusIcon = (itemStatus: string) => {
        switch (itemStatus) {
            case 'safe':
                return <CheckCircle2 className="h-4 w-4 text-emerald-500" />
            case 'risky':
                return <AlertTriangle className="h-4 w-4 text-yellow-500" />
            case 'unsafe':
                return <XCircle className="h-4 w-4 text-red-500" />
            default:
                return <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        }
    }

    const getOverallStatusColor = (status: string) => {
        switch (status) {
            case 'safe': return 'bg-emerald-100 text-emerald-700'
            case 'risky': return 'bg-yellow-100 text-yellow-700'
            case 'unsafe': return 'bg-red-100 text-red-700'
            default: return 'bg-slate-100 text-slate-700'
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[550px] max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Safe Update Tester</DialogTitle>
                    <DialogDescription>
                        Test updates in a temporary sandbox before applying them to your live site.
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4 space-y-4">
                    {/* Loading State */}
                    {status === 'loading' && (
                        <div className="flex flex-col items-center justify-center py-8 gap-3">
                            <Loader2 className="h-8 w-8 animate-spin text-blue-500" />
                            <p className="text-sm text-muted-foreground">Loading available updates...</p>
                        </div>
                    )}

                    {/* Selection State */}
                    {status === 'select' && (
                        <div className="space-y-4">
                            {availablePlugins.length === 0 ? (
                                <div className="text-center py-8 space-y-4">
                                    <div className="mx-auto w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center">
                                        <CheckCircle2 className="h-6 w-6 text-emerald-600" />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        All plugins and themes are up to date!
                                    </p>
                                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                                        Close
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">
                                            {availablePlugins.length} update{availablePlugins.length !== 1 ? 's' : ''} available
                                        </span>
                                        <div className="flex gap-2">
                                            <Button variant="ghost" size="sm" onClick={selectAll}>Select All</Button>
                                            <Button variant="ghost" size="sm" onClick={selectNone}>Select None</Button>
                                        </div>
                                    </div>

                                    <div className="border rounded-lg divide-y max-h-60 overflow-y-auto">
                                        {availablePlugins.map((plugin) => {
                                            const id = plugin.file || plugin.slug || ''
                                            const isSelected = selectedPlugins.includes(id)
                                            return (
                                                <label
                                                    key={id}
                                                    className={`flex items-center gap-3 p-3 cursor-pointer hover:bg-slate-50 transition-colors ${isSelected ? 'bg-blue-50/50' : ''}`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={() => togglePlugin(id)}
                                                        className="rounded border-slate-300"
                                                    />
                                                    <Package className="h-4 w-4 text-slate-400" />
                                                    <div className="flex-1 min-w-0">
                                                        <div className="font-medium text-sm truncate">{plugin.name}</div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {plugin.current_version} → {plugin.new_version}
                                                        </div>
                                                    </div>
                                                    {plugin.requires_php && (
                                                        <span className="text-[10px] bg-slate-100 px-1.5 py-0.5 rounded">
                                                            PHP {plugin.requires_php}+
                                                        </span>
                                                    )}
                                                </label>
                                            )
                                        })}
                                    </div>

                                    <Button
                                        className="w-full"
                                        disabled={selectedPlugins.length === 0}
                                        onClick={startTest}
                                    >
                                        <PlayCircle className="h-4 w-4 mr-2" />
                                        Test {selectedPlugins.length} Update{selectedPlugins.length !== 1 ? 's' : ''}
                                    </Button>
                                </>
                            )}
                        </div>
                    )}

                    {/* Running State */}
                    {status === 'running' && (
                        <div className="space-y-4 py-4">
                            <div className="flex flex-col items-center gap-4">
                                <div className="relative">
                                    <div className="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                                        <RefreshCw className="h-8 w-8 text-blue-600 animate-spin" />
                                    </div>
                                </div>
                                <div className="text-center">
                                    <h3 className="font-semibold">Running Safe Update Test</h3>
                                    <p className="text-sm text-muted-foreground mt-1">{currentStep || 'Starting...'}</p>
                                </div>
                            </div>

                            {/* Progress Bar */}
                            <div className="h-2 bg-slate-200 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-blue-500 transition-all duration-500 ease-out"
                                    style={{ width: `${progressPercent}%` }}
                                />
                            </div>

                            {/* Live Log */}
                            <div className="bg-slate-950 text-slate-50 p-3 rounded-md font-mono text-xs h-32 overflow-y-auto border border-slate-800">
                                {progressLogs.map((log, i) => {
                                    const isActive = i === progressLogs.length - 1
                                    return (
                                        <div
                                            key={i}
                                            className={`flex items-start gap-1 ${isActive
                                                ? 'text-emerald-400'
                                                : 'text-slate-500'
                                                }`}
                                        >
                                            <span className="text-slate-600">&gt;</span>
                                            <span className={isActive ? 'animate-pulse' : ''}>
                                                {log}
                                            </span>
                                            {isActive && (
                                                <span className="inline-block w-2 h-4 bg-emerald-400 animate-[blink_1s_infinite] ml-0.5" />
                                            )}
                                        </div>
                                    )
                                })}
                                {progressLogs.length === 0 && (
                                    <div className="flex items-center gap-1 text-emerald-400">
                                        <span className="text-slate-600">&gt;</span>
                                        <span className="animate-pulse">Initializing...</span>
                                        <span className="inline-block w-2 h-4 bg-emerald-400 animate-[blink_1s_infinite] ml-0.5" />
                                    </div>
                                )}
                            </div>

                            <p className="text-xs text-center text-muted-foreground">
                                {progressPercent < 50 ? 'Downloading updates...' : 'Testing updates...'} Please don't close this window.
                            </p>
                        </div>
                    )}

                    {/* Complete State */}
                    {status === 'complete' && result && (
                        <div className="space-y-4">
                            <div className="flex items-center gap-3">
                                <div className={`px-3 py-1 rounded-full text-sm font-medium ${getOverallStatusColor(result.summary.overall_status)}`}>
                                    {result.summary.overall_status === 'safe' && '✓ Safe to Update'}
                                    {result.summary.overall_status === 'risky' && '⚠ Proceed with Caution'}
                                    {result.summary.overall_status === 'unsafe' && '✗ Issues Detected'}
                                </div>
                            </div>

                            <div className="border rounded-lg divide-y">
                                {result.items.plugins.map((plugin) => (
                                    <div key={plugin.slug} className="p-3 flex items-start gap-3">
                                        {getStatusIcon(plugin.status)}
                                        <div className="flex-1">
                                            <div className="font-medium text-sm">{plugin.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {plugin.from_version} → {plugin.to_version}
                                            </div>
                                            {plugin.issues.length > 0 && (
                                                <div className="mt-2 space-y-1">
                                                    {plugin.issues.map((issue, i) => (
                                                        <div key={i} className="text-xs bg-red-50 text-red-700 p-2 rounded">
                                                            {issue.message}
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* Health Checks Summary */}
                            {result.health_checks.length > 0 && (
                                <div className="border rounded-lg p-3">
                                    <div className="text-sm font-medium mb-2">Health Checks</div>
                                    <div className="space-y-1">
                                        {result.health_checks.map((check, i) => (
                                            <div key={i} className="flex items-center gap-2 text-xs">
                                                {check.status_code === 200 && !check.wsod_detected
                                                    ? <CheckCircle2 className="h-3 w-3 text-emerald-500" />
                                                    : <XCircle className="h-3 w-3 text-red-500" />
                                                }
                                                <span>{check.label}</span>
                                                <span className="text-muted-foreground">({check.status_code})</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* AI Summary Section */}
                            {window.sbwpData.isPro && sessionId && (
                                <div className="border border-violet-200 rounded-lg p-3 bg-violet-50/50">
                                    <div className="flex items-center gap-2 mb-2">
                                        <Sparkles className="h-4 w-4 text-violet-500" />
                                        <span className="text-sm font-medium">AI Explanation</span>
                                    </div>
                                    {aiSummary ? (
                                        <p className="text-sm text-slate-700">{aiSummary}</p>
                                    ) : (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-violet-600 border-violet-200 hover:bg-violet-100"
                                            onClick={async () => {
                                                setLoadingAI(true)
                                                try {
                                                    const response = await humanizeReport(sessionId)
                                                    setAiSummary(response.summary)
                                                } catch (e) {
                                                    setAiSummary('Could not generate AI summary. Please check your API key in Settings.')
                                                } finally {
                                                    setLoadingAI(false)
                                                }
                                            }}
                                            disabled={loadingAI}
                                        >
                                            {loadingAI ? (
                                                <><Loader2 className="h-4 w-4 animate-spin mr-2" /> Generating...</>
                                            ) : (
                                                <>Explain in Plain English</>
                                            )}
                                        </Button>
                                    )}
                                </div>
                            )}

                            <div className="flex gap-2">
                                {result.summary.overall_status === 'safe' && (
                                    <Button
                                        className="flex-1 bg-emerald-600 hover:bg-emerald-700"
                                        onClick={() => window.location.href = '/wp-admin/update-core.php'}
                                    >
                                        Apply Updates
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    className={result.summary.overall_status === 'safe' ? '' : 'flex-1'}
                                    onClick={() => onOpenChange(false)}
                                >
                                    Close
                                </Button>
                            </div>
                        </div>
                    )}

                    {/* Failed/Error State */}
                    {(status === 'failed' || status === 'error') && (
                        <div className="text-center py-8 space-y-4">
                            <div className="mx-auto w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                                <XCircle className="h-6 w-6 text-red-600" />
                            </div>
                            <div>
                                <h3 className="font-bold">Test Failed</h3>
                                <p className="text-sm text-muted-foreground mt-1">{error}</p>
                            </div>
                            <div className="flex gap-2 justify-center">
                                <Button variant="outline" onClick={loadAvailableUpdates}>
                                    Try Again
                                </Button>
                                <Button variant="ghost" onClick={() => onOpenChange(false)}>
                                    Close
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
