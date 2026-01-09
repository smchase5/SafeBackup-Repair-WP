import { useState, useEffect, useRef } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import {
    Loader2, Bug, AlertTriangle, CheckCircle2, Mail, Copy, AlertCircle,
    Zap, Search, XCircle, Download, Power,
    ChevronDown, ChevronUp, Code, FileWarning
} from "lucide-react"

interface ConflictScannerDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

type ScanStatus = 'idle' | 'quick_scanning' | 'scanning' | 'found' | 'clean' | 'core_issue' | 'email_ready'
type ScanMode = 'quick' | 'deep'

interface CulpritInfo {
    type: string
    slug: string
    name: string
    version: string
    latest_version?: string
    is_outdated?: boolean
    author?: string
}

interface Issue {
    type: string
    severity: 'error' | 'warning' | 'info'
    source: { type: string; slug?: string; name?: string }
    message: string
    count?: number
}

interface ScanResult {
    type?: 'quick' | 'deep'
    status: string
    culprit: CulpritInfo | null
    multi_plugin_conflict?: any
    theme_conflict: boolean
    outdated_plugins: Array<{ name: string; current_version: string; latest_version: string }>
    ai_analysis?: {
        diagnosis: string
        recommendation: string
        confidence: string
        culprit_plugin?: string
        fix_actions?: string[]
    }
    clean_slate_passed?: boolean
    environment?: {
        php_version: string
        wp_version: string
        active_plugins_count: number
        active_theme: string
    }
    issues?: Issue[]
    js_errors?: { errors: any[]; summary: any[]; count: number }
    custom_code?: { sources: any; issues: any[]; summary: any }
    scan_duration?: number
}

interface GeneratedEmail {
    subject: string
    body: string
    to_email?: string
}

interface ScanStep {
    id: string
    label: string
    status: 'pending' | 'running' | 'done' | 'error'
    detail?: string
}

export function ConflictScannerDialog({ open, onOpenChange }: ConflictScannerDialogProps) {
    const [status, setStatus] = useState<ScanStatus>('idle')
    const [scanMode, setScanMode] = useState<ScanMode>('quick')
    const [userContext, setUserContext] = useState('')
    const [progress, setProgress] = useState({ message: '', percent: 0 })
    const [result, setResult] = useState<ScanResult | null>(null)
    const [generatedEmail, setGeneratedEmail] = useState<GeneratedEmail | null>(null)
    const [outdatedPlugins, setOutdatedPlugins] = useState<any[]>([])
    const [showOutdatedWarning, setShowOutdatedWarning] = useState(false)
    const [emailCopied, setEmailCopied] = useState(false)
    const [showDetails, setShowDetails] = useState(false)
    const [scanSteps, setScanSteps] = useState<ScanStep[]>([])
    const pollInterval = useRef<NodeJS.Timeout | null>(null)

    // Quick scan steps
    const quickScanSteps: ScanStep[] = [
        { id: 'js', label: 'Checking JavaScript errors', status: 'pending' },
        { id: 'php', label: 'Scanning PHP errors', status: 'pending' },
        { id: 'code', label: 'Analyzing custom code', status: 'pending' },
        { id: 'conflicts', label: 'Checking known conflicts', status: 'pending' },
        { id: 'ai', label: 'AI diagnosis', status: 'pending' },
    ]

    // Deep scan steps
    const deepScanSteps: ScanStep[] = [
        { id: 'prep', label: 'Preparing sandbox', status: 'pending' },
        { id: 'clone', label: 'Creating test environment', status: 'pending' },
        { id: 'baseline', label: 'Capturing baseline', status: 'pending' },
        { id: 'isolation', label: 'Isolating conflict', status: 'pending' },
        { id: 'ai', label: 'AI diagnosis', status: 'pending' },
    ]

    // Check for outdated plugins on open
    useEffect(() => {
        if (open && window.sbwpData.isPro) {
            checkOutdatedPlugins()
        }
        return () => {
            if (pollInterval.current) clearInterval(pollInterval.current)
        }
    }, [open])

    const checkOutdatedPlugins = async () => {
        try {
            const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/check-outdated`, {
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const data = await res.json()
            setOutdatedPlugins(data.plugins || [])
            if (data.count > 0) {
                setShowOutdatedWarning(true)
            }
        } catch (e) {
            console.error('Failed to check outdated plugins:', e)
        }
    }

    const startScan = async () => {
        if (scanMode === 'quick') {
            startQuickScan()
        } else {
            startDeepScan()
        }
    }

    const startQuickScan = async () => {
        setStatus('quick_scanning')
        setResult(null)
        setGeneratedEmail(null)
        setShowOutdatedWarning(false)
        setScanSteps(quickScanSteps)

        // Animate through steps

        try {
            // Update steps as we go
            const updateStep = (id: string, status: 'running' | 'done' | 'error', detail?: string) => {
                setScanSteps(prev => prev.map(s =>
                    s.id === id ? { ...s, status, detail } : s
                ))
            }

            updateStep('js', 'running')
            await new Promise(r => setTimeout(r, 300))

            // Make the actual API call
            const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/quick`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.sbwpData.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_context: userContext })
            })
            const data = await res.json()

            // Animate through remaining steps
            updateStep('js', 'done', data.js_errors?.count ? `${data.js_errors.count} errors` : 'No errors')
            await new Promise(r => setTimeout(r, 200))

            updateStep('php', 'running')
            await new Promise(r => setTimeout(r, 200))
            updateStep('php', 'done', data.php_errors?.count ? `${data.php_errors.count} errors` : 'No errors')

            updateStep('code', 'running')
            await new Promise(r => setTimeout(r, 200))
            updateStep('code', 'done', data.custom_code?.summary?.custom_code_sources ?
                `${data.custom_code.summary.custom_code_sources} sources` : 'None found')

            updateStep('conflicts', 'running')
            await new Promise(r => setTimeout(r, 200))
            updateStep('conflicts', 'done', data.known_conflicts?.length ?
                `${data.known_conflicts.length} found` : 'None')

            updateStep('ai', 'running')
            await new Promise(r => setTimeout(r, 300))
            updateStep('ai', 'done')

            // Handle result
            handleQuickScanComplete(data)

        } catch (e) {
            console.error('Quick scan error:', e)
            setStatus('idle')
        }
    }

    const handleQuickScanComplete = (data: ScanResult) => {
        setResult(data)

        if (data.issues && data.issues.length > 0) {
            const hasErrors = data.issues.some(i => i.severity === 'error')
            if (hasErrors) {
                setStatus('found')
            } else {
                setStatus('clean')
            }
        } else {
            setStatus('clean')
        }
    }

    const startDeepScan = async () => {
        setStatus('scanning')
        setProgress({ message: 'Starting deep scan...', percent: 5 })
        setResult(null)
        setGeneratedEmail(null)
        setShowOutdatedWarning(false)
        setScanSteps(deepScanSteps)

        try {
            const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/start`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.sbwpData.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_context: userContext })
            })
            const data = await res.json()

            if (!data.session_id) {
                throw new Error('Failed to start scan')
            }

            // Start polling for progress
            pollForProgress(data.session_id)

        } catch (e) {
            console.error('Scan start error:', e)
            setStatus('idle')
        }
    }

    const pollForProgress = (sessionId: number) => {
        pollInterval.current = setInterval(async () => {
            try {
                const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/session/${sessionId}`, {
                    headers: { 'X-WP-Nonce': window.sbwpData.nonce }
                })
                const session = await res.json()

                if (session.progress) {
                    setProgress({
                        message: session.progress.message || 'Scanning...',
                        percent: session.progress.percent || 0
                    })

                    // Update step indicators based on progress
                    updateDeepScanSteps(session.progress.step)
                }

                if (session.status === 'completed') {
                    clearInterval(pollInterval.current!)
                    handleDeepScanComplete(session.result)
                } else if (session.status === 'failed') {
                    clearInterval(pollInterval.current!)
                    setStatus('idle')
                    console.error('Scan failed:', session.error_message)
                }
            } catch (e) {
                console.error('Poll error:', e)
            }
        }, 1500)
    }

    const updateDeepScanSteps = (currentStep: string) => {
        const stepOrder = ['prep', 'clone', 'baseline', 'isolation', 'ai']
        const currentIndex = stepOrder.indexOf(currentStep) !== -1 ? stepOrder.indexOf(currentStep) : 0

        setScanSteps(prev => prev.map((step) => {
            const stepIndex = stepOrder.indexOf(step.id)
            if (stepIndex < currentIndex) return { ...step, status: 'done' }
            if (stepIndex === currentIndex) return { ...step, status: 'running' }
            return { ...step, status: 'pending' }
        }))
    }

    const handleDeepScanComplete = (scanResult: ScanResult) => {
        setResult({ ...scanResult, type: 'deep' })
        setScanSteps(prev => prev.map(s => ({ ...s, status: 'done' })))

        if (scanResult.culprit) {
            if (scanResult.culprit.type === 'core_or_server') {
                setStatus('core_issue')
            } else {
                setStatus('found')
            }
        } else if (scanResult.multi_plugin_conflict) {
            setStatus('found')
        } else {
            setStatus('clean')
        }
    }

    const generateEmail = async () => {
        if (!result) return

        try {
            const sessionsRes = await fetch(`${window.sbwpData.restUrl}/conflict-scan/sessions`, {
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const sessions = await sessionsRes.json()
            const latestSession = sessions[0]

            if (!latestSession) return

            const res = await fetch(`${window.sbwpData.restUrl}/conflict-scan/generate-email/${latestSession.id}`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const email = await res.json()

            if (!email.error) {
                setGeneratedEmail(email)
                setStatus('email_ready')
            }
        } catch (e) {
            console.error('Email generation error:', e)
        }
    }

    const copyEmailToClipboard = () => {
        if (!generatedEmail) return
        const text = `Subject: ${generatedEmail.subject}\n\n${generatedEmail.body}`
        navigator.clipboard.writeText(text)
        setEmailCopied(true)
        setTimeout(() => setEmailCopied(false), 2000)
    }

    const handleDisablePlugin = async (pluginSlug: string) => {
        // TODO: Implement plugin disable via REST
        console.log('Disable plugin:', pluginSlug)
    }

    const resetDialog = () => {
        setStatus('idle')
        setUserContext('')
        setProgress({ message: '', percent: 0 })
        setResult(null)
        setGeneratedEmail(null)
        setShowOutdatedWarning(false)
        setScanSteps([])
        setShowDetails(false)
    }

    // Get primary issue for display
    const getPrimaryIssue = (): Issue | null => {
        if (!result?.issues?.length) return null
        return result.issues.find(i => i.severity === 'error') || result.issues[0]
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => {
            if (!isOpen) resetDialog()
            onOpenChange(isOpen)
        }}>
            <DialogContent className="sm:max-w-[550px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <div className="p-1.5 bg-gradient-to-br from-orange-500 to-red-500 rounded-lg">
                            <Bug className="h-4 w-4 text-white" />
                        </div>
                        AI Conflict Scanner
                    </DialogTitle>
                    <DialogDescription>
                        Find and fix what's breaking your site
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4 space-y-4">
                    {/* Idle State */}
                    {status === 'idle' && (
                        <div className="space-y-4">
                            {/* Problem description */}
                            <div>
                                <label className="text-sm font-medium mb-2 block">
                                    What's wrong?
                                </label>
                                <Textarea
                                    placeholder="e.g., The Elementor editor shows a blank popup when I try to edit widgets..."
                                    value={userContext}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setUserContext(e.target.value)}
                                    rows={2}
                                    className="resize-none"
                                />
                            </div>

                            {/* Scan mode toggle */}
                            <div className="space-y-2">
                                <label className="text-sm font-medium">Choose scan type:</label>

                                <button
                                    onClick={() => setScanMode('quick')}
                                    className={`w-full p-4 rounded-lg border-2 transition-all text-left ${scanMode === 'quick'
                                        ? 'border-orange-500 bg-orange-50'
                                        : 'border-gray-200 hover:border-gray-300'
                                        }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Zap className={`h-5 w-5 ${scanMode === 'quick' ? 'text-orange-500' : 'text-gray-400'}`} />
                                            <span className="font-semibold">Quick Check</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">~10 seconds</span>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-2 ml-7">
                                        <strong>Best for:</strong> JavaScript errors, console errors, broken page builders, frontend issues
                                    </p>
                                    <p className="text-xs text-gray-500 mt-1 ml-7">
                                        Scans error logs, captures live JS errors from your browser, checks Code Snippets & custom code
                                    </p>
                                </button>

                                <button
                                    onClick={() => setScanMode('deep')}
                                    className={`w-full p-4 rounded-lg border-2 transition-all text-left ${scanMode === 'deep'
                                        ? 'border-orange-500 bg-orange-50'
                                        : 'border-gray-200 hover:border-gray-300'
                                        }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Search className={`h-5 w-5 ${scanMode === 'deep' ? 'text-orange-500' : 'text-gray-400'}`} />
                                            <span className="font-semibold">Deep Scan</span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">~2 minutes</span>
                                    </div>
                                    <p className="text-xs text-muted-foreground mt-2 ml-7">
                                        <strong>Best for:</strong> PHP fatal errors, white screen of death, 500 errors, complete site crashes
                                    </p>
                                    <p className="text-xs text-gray-500 mt-1 ml-7">
                                        Creates a sandbox and tests each plugin one-by-one to find which one crashes your site
                                    </p>
                                </button>
                            </div>

                            {/* Outdated plugins warning */}
                            {showOutdatedWarning && outdatedPlugins.length > 0 && (
                                <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                    <div className="flex items-start gap-2">
                                        <AlertCircle className="h-5 w-5 text-yellow-600 mt-0.5 shrink-0" />
                                        <div>
                                            <p className="text-sm font-medium text-yellow-800">
                                                {outdatedPlugins.length} plugin{outdatedPlugins.length > 1 ? 's need' : ' needs'} updates
                                            </p>
                                            <p className="text-xs text-yellow-700 mt-0.5">
                                                Outdated plugins often cause conflicts
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <Button
                                size="lg"
                                onClick={startScan}
                                className="w-full bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600"
                            >
                                {scanMode === 'quick' ? (
                                    <>
                                        <Zap className="h-4 w-4 mr-2" />
                                        Start Quick Check
                                    </>
                                ) : (
                                    <>
                                        <Search className="h-4 w-4 mr-2" />
                                        Start Deep Scan
                                    </>
                                )}
                            </Button>

                            <p className="text-xs text-center text-muted-foreground">
                                {scanMode === 'quick'
                                    ? 'Checks logs and recent errors instantly'
                                    : 'Creates a sandbox to test each plugin safely'
                                }
                            </p>
                        </div>
                    )}

                    {/* Scanning State (Quick) */}
                    {status === 'quick_scanning' && (
                        <div className="space-y-4">
                            <div className="space-y-2">
                                {scanSteps.map(step => (
                                    <div key={step.id} className="flex items-center gap-3 py-1.5">
                                        <div className="w-5 h-5 flex items-center justify-center">
                                            {step.status === 'pending' && (
                                                <div className="w-2 h-2 rounded-full bg-gray-200" />
                                            )}
                                            {step.status === 'running' && (
                                                <Loader2 className="w-4 h-4 animate-spin text-orange-500" />
                                            )}
                                            {step.status === 'done' && (
                                                <CheckCircle2 className="w-4 h-4 text-emerald-500" />
                                            )}
                                            {step.status === 'error' && (
                                                <XCircle className="w-4 h-4 text-red-500" />
                                            )}
                                        </div>
                                        <span className={`text-sm flex-1 ${step.status === 'pending' ? 'text-gray-400' :
                                            step.status === 'running' ? 'text-orange-600 font-medium' :
                                                'text-gray-700'
                                            }`}>
                                            {step.label}
                                        </span>
                                        {step.detail && (
                                            <span className="text-xs text-muted-foreground">{step.detail}</span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Scanning State (Deep) */}
                    {status === 'scanning' && (
                        <div className="space-y-4 text-center py-4">
                            <div className="relative mx-auto w-16 h-16">
                                <div className="absolute inset-0 bg-orange-100 rounded-full animate-ping opacity-25" />
                                <div className="relative bg-orange-100 rounded-full w-16 h-16 flex items-center justify-center">
                                    <Search className="h-8 w-8 text-orange-500" />
                                </div>
                            </div>
                            <h3 className="font-semibold text-lg">Deep Scanning...</h3>
                            <p className="text-sm text-muted-foreground">{progress.message}</p>
                            <div className="h-2 bg-slate-100 rounded-full overflow-hidden w-full">
                                <div
                                    className="h-full bg-gradient-to-r from-orange-500 to-red-500 transition-all duration-300"
                                    style={{ width: `${progress.percent}%` }}
                                />
                            </div>
                            <div className="space-y-1">
                                {scanSteps.map(step => (
                                    <div key={step.id} className="flex items-center gap-2 justify-center text-xs">
                                        {step.status === 'done' && <CheckCircle2 className="w-3 h-3 text-emerald-500" />}
                                        {step.status === 'running' && <Loader2 className="w-3 h-3 animate-spin text-orange-500" />}
                                        {step.status === 'pending' && <div className="w-3 h-3 rounded-full border border-gray-300" />}
                                        <span className={step.status === 'running' ? 'text-orange-600' : 'text-gray-500'}>
                                            {step.label}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Issues Found State */}
                    {status === 'found' && result && (
                        <div className="space-y-4">
                            {/* Primary finding card */}
                            <div className="bg-gradient-to-br from-red-50 to-orange-50 border border-red-200 rounded-xl p-4">
                                <div className="flex items-center gap-2 text-red-700 mb-3">
                                    <AlertTriangle className="h-5 w-5" />
                                    <h3 className="font-bold">
                                        {result.issues?.length || 0} Issue{(result.issues?.length || 0) !== 1 ? 's' : ''} Found
                                    </h3>
                                    {result.ai_analysis?.confidence && (
                                        <span className="ml-auto text-xs bg-red-100 px-2 py-0.5 rounded-full">
                                            {result.ai_analysis.confidence} confidence
                                        </span>
                                    )}
                                </div>

                                {/* Show primary culprit or issue */}
                                {(result.culprit || getPrimaryIssue()) && (
                                    <div className="bg-white/80 backdrop-blur rounded-lg p-3 mb-3 border border-red-100">
                                        <div className="flex items-center gap-2">
                                            {result.culprit ? (
                                                <>
                                                    <Power className="h-4 w-4 text-red-500" />
                                                    <span className="font-semibold">{result.culprit.name}</span>
                                                    {result.culprit.is_outdated && (
                                                        <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full">
                                                            Update available
                                                        </span>
                                                    )}
                                                </>
                                            ) : getPrimaryIssue() && (
                                                <>
                                                    {getPrimaryIssue()?.type === 'javascript' && <Code className="h-4 w-4 text-red-500" />}
                                                    {getPrimaryIssue()?.type === 'php' && <FileWarning className="h-4 w-4 text-red-500" />}
                                                    <span className="font-semibold">
                                                        {getPrimaryIssue()?.source?.name || getPrimaryIssue()?.source?.slug || 'Unknown source'}
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                        {getPrimaryIssue() && (
                                            <p className="text-sm text-gray-600 mt-1 line-clamp-2">
                                                {getPrimaryIssue()?.message}
                                            </p>
                                        )}
                                    </div>
                                )}

                                {/* AI Diagnosis */}
                                {result.ai_analysis && (
                                    <div className="space-y-2 text-sm">
                                        <p><strong>💡 Diagnosis:</strong> {result.ai_analysis.diagnosis}</p>
                                        {result.ai_analysis.recommendation && (
                                            <p className="text-red-700">{result.ai_analysis.recommendation}</p>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Quick Fix Actions */}
                            <div className="grid grid-cols-2 gap-2">
                                {result.culprit?.is_outdated && (
                                    <Button variant="outline" className="flex-1 text-sm" size="sm">
                                        <Download className="h-3 w-3 mr-1.5" />
                                        Update Plugin
                                    </Button>
                                )}
                                {result.culprit && (
                                    <Button
                                        variant="outline"
                                        className="flex-1 text-sm"
                                        size="sm"
                                        onClick={() => handleDisablePlugin(result.culprit!.slug)}
                                    >
                                        <Power className="h-3 w-3 mr-1.5" />
                                        Disable Plugin
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    className="flex-1 text-sm"
                                    size="sm"
                                    onClick={generateEmail}
                                >
                                    <Mail className="h-3 w-3 mr-1.5" />
                                    Email Developer
                                </Button>
                                {result.type === 'quick' && (
                                    <Button
                                        variant="outline"
                                        className="flex-1 text-sm"
                                        size="sm"
                                        onClick={() => { setScanMode('deep'); startDeepScan(); }}
                                    >
                                        <Search className="h-3 w-3 mr-1.5" />
                                        Deep Scan
                                    </Button>
                                )}
                            </div>

                            {/* Expandable details */}
                            {result.issues && result.issues.length > 1 && (
                                <button
                                    onClick={() => setShowDetails(!showDetails)}
                                    className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground w-full justify-center"
                                >
                                    {showDetails ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />}
                                    {showDetails ? 'Hide' : 'Show'} all {result.issues.length} issues
                                </button>
                            )}

                            {showDetails && result.issues && (
                                <div className="max-h-40 overflow-y-auto space-y-1 text-xs">
                                    {result.issues.map((issue, idx) => (
                                        <div key={idx} className={`p-2 rounded flex items-start gap-2 ${issue.severity === 'error' ? 'bg-red-50' :
                                            issue.severity === 'warning' ? 'bg-yellow-50' : 'bg-gray-50'
                                            }`}>
                                            <span className={`font-mono uppercase text-[10px] px-1 rounded ${issue.severity === 'error' ? 'bg-red-100 text-red-700' :
                                                issue.severity === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100'
                                                }`}>
                                                {issue.type}
                                            </span>
                                            <span className="flex-1 line-clamp-2">{issue.message}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <Button variant="outline" onClick={() => onOpenChange(false)} className="w-full">
                                Close
                            </Button>
                        </div>
                    )}

                    {/* Core/Server Issue State */}
                    {status === 'core_issue' && result && (
                        <div className="space-y-4 text-center">
                            <div className="mx-auto w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                                <AlertCircle className="h-8 w-8 text-blue-600" />
                            </div>
                            <h3 className="font-bold text-lg">Not a Plugin Conflict</h3>
                            <p className="text-muted-foreground">
                                The issue persists even with all plugins disabled. This suggests a server, WordPress core, or theme issue.
                            </p>
                            {result.ai_analysis && (
                                <p className="text-sm">{result.ai_analysis.recommendation}</p>
                            )}
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                        </div>
                    )}

                    {/* No Issues State */}
                    {status === 'clean' && (
                        <div className="text-center space-y-4 py-4">
                            <div className="mx-auto w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center">
                                <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                            </div>
                            <h3 className="font-bold text-lg">Looking Good!</h3>
                            <p className="text-muted-foreground">
                                No obvious conflicts detected{result?.scan_duration ? ` in ${result.scan_duration}s` : ''}.
                            </p>
                            {result?.type === 'quick' && (
                                <p className="text-sm text-muted-foreground">
                                    Run a <button
                                        onClick={() => { setScanMode('deep'); startDeepScan(); }}
                                        className="text-orange-600 underline"
                                    >deep scan</button> for thorough analysis.
                                </p>
                            )}
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                        </div>
                    )}

                    {/* Email Ready State */}
                    {status === 'email_ready' && generatedEmail && (
                        <div className="space-y-4">
                            <div className="flex items-center gap-2 text-emerald-700">
                                <Mail className="h-5 w-5" />
                                <h3 className="font-semibold">Developer Email Ready</h3>
                            </div>

                            <div className="bg-slate-50 border rounded-lg p-3 space-y-2">
                                <div>
                                    <p className="text-xs text-muted-foreground">Subject:</p>
                                    <p className="text-sm font-medium">{generatedEmail.subject}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">Body:</p>
                                    <pre className="text-sm whitespace-pre-wrap max-h-48 overflow-y-auto">
                                        {generatedEmail.body}
                                    </pre>
                                </div>
                            </div>

                            <div className="flex gap-2">
                                <Button onClick={copyEmailToClipboard} className="flex-1">
                                    <Copy className="h-4 w-4 mr-2" />
                                    {emailCopied ? 'Copied!' : 'Copy to Clipboard'}
                                </Button>
                                <Button variant="outline" onClick={() => setStatus('found')}>
                                    Back
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
