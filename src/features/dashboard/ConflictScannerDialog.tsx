import { useState, useEffect, useRef } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Loader2, Bug, AlertTriangle, CheckCircle2, Mail, Copy, AlertCircle } from "lucide-react"

interface ConflictScannerDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

type ScanStatus = 'idle' | 'scanning' | 'found' | 'clean' | 'core_issue' | 'email_ready'

interface CulpritInfo {
    type: string
    slug: string
    name: string
    version: string
    latest_version?: string
    is_outdated?: boolean
    author?: string
}

interface ScanResult {
    status: string
    culprit: CulpritInfo | null
    multi_plugin_conflict?: any
    theme_conflict: boolean
    outdated_plugins: Array<{ name: string; current_version: string; latest_version: string }>
    ai_analysis?: {
        diagnosis: string
        recommendation: string
        confidence: string
    }
    clean_slate_passed?: boolean
    environment?: {
        php_version: string
        wp_version: string
        active_plugins_count: number
        active_theme: string
    }
}

interface GeneratedEmail {
    subject: string
    body: string
    to_email?: string
}

export function ConflictScannerDialog({ open, onOpenChange }: ConflictScannerDialogProps) {
    const [status, setStatus] = useState<ScanStatus>('idle')
    const [userContext, setUserContext] = useState('')
    const [progress, setProgress] = useState({ message: '', percent: 0 })
    const [result, setResult] = useState<ScanResult | null>(null)
    const [generatedEmail, setGeneratedEmail] = useState<GeneratedEmail | null>(null)
    const [outdatedPlugins, setOutdatedPlugins] = useState<any[]>([])
    const [showOutdatedWarning, setShowOutdatedWarning] = useState(false)
    const [emailCopied, setEmailCopied] = useState(false)
    const pollInterval = useRef<NodeJS.Timeout | null>(null)

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
        setStatus('scanning')
        setProgress({ message: 'Starting conflict scan...', percent: 5 })
        setResult(null)
        setGeneratedEmail(null)
        setShowOutdatedWarning(false)

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
                }

                if (session.status === 'completed') {
                    clearInterval(pollInterval.current!)
                    handleScanComplete(session.result)
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

    const handleScanComplete = (scanResult: ScanResult) => {
        setResult(scanResult)

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
            // Get session ID from URL or store it
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

            if (email.subject && email.body) {
                setGeneratedEmail(email)
                setStatus('email_ready')
            }
        } catch (e) {
            console.error('Email generation error:', e)
        }
    }

    const copyEmailToClipboard = () => {
        if (!generatedEmail) return
        const fullEmail = `Subject: ${generatedEmail.subject}\n\n${generatedEmail.body}`
        navigator.clipboard.writeText(fullEmail)
        setEmailCopied(true)
        setTimeout(() => setEmailCopied(false), 2000)
    }

    const resetDialog = () => {
        setStatus('idle')
        setUserContext('')
        setProgress({ message: '', percent: 0 })
        setResult(null)
        setGeneratedEmail(null)
        setShowOutdatedWarning(false)
    }

    return (
        <Dialog open={open} onOpenChange={(isOpen) => {
            if (!isOpen) resetDialog()
            onOpenChange(isOpen)
        }}>
            <DialogContent className="sm:max-w-[550px]">
                <DialogHeader>
                    <DialogTitle>AI Conflict Scanner</DialogTitle>
                    <DialogDescription>
                        Automatically identify which plugin is causing issues on your site.
                    </DialogDescription>
                </DialogHeader>

                <div className="py-4 space-y-4">
                    {/* Idle State */}
                    {status === 'idle' && (
                        <div className="space-y-4">
                            <div className="mx-auto w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center">
                                <Bug className="h-8 w-8 text-orange-600" />
                            </div>

                            <div>
                                <label className="text-sm font-medium mb-2 block">
                                    Describe the issue you're experiencing (optional):
                                </label>
                                <Textarea
                                    placeholder="e.g., When I try to edit block settings, the page crashes with a white screen..."
                                    value={userContext}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setUserContext(e.target.value)}
                                    rows={3}
                                />
                            </div>

                            {showOutdatedWarning && outdatedPlugins.length > 0 && (
                                <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                                    <div className="flex items-start gap-2">
                                        <AlertCircle className="h-5 w-5 text-yellow-600 mt-0.5" />
                                        <div>
                                            <p className="text-sm font-medium text-yellow-800">
                                                {outdatedPlugins.length} plugin{outdatedPlugins.length > 1 ? 's have' : ' has'} updates available
                                            </p>
                                            <p className="text-xs text-yellow-700 mt-1">
                                                Consider updating before scanning. Outdated plugins often cause conflicts.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <p className="text-sm text-muted-foreground text-center">
                                This runs in a sandbox—your live site won't be affected.
                            </p>

                            <Button
                                size="lg"
                                onClick={startScan}
                                className="w-full bg-orange-600 hover:bg-orange-700"
                            >
                                Start Conflict Scan
                            </Button>
                        </div>
                    )}

                    {/* Scanning State */}
                    {status === 'scanning' && (
                        <div className="space-y-4 text-center py-4">
                            <Loader2 className="h-12 w-12 animate-spin text-orange-500 mx-auto" />
                            <h3 className="font-semibold text-lg">Scanning...</h3>
                            <p className="text-sm text-muted-foreground">{progress.message}</p>
                            <div className="h-2 bg-slate-100 rounded-full overflow-hidden w-full">
                                <div
                                    className="h-full bg-orange-500 transition-all duration-300"
                                    style={{ width: `${progress.percent}%` }}
                                />
                            </div>
                        </div>
                    )}

                    {/* Conflict Found State */}
                    {status === 'found' && result && (
                        <div className="space-y-4">
                            <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                                <div className="flex items-center gap-3 text-red-800 mb-3">
                                    <AlertTriangle className="h-6 w-6" />
                                    <h3 className="font-bold text-lg">Conflict Detected</h3>
                                </div>

                                {result.culprit && (
                                    <div className="bg-white p-3 rounded border border-red-100 mb-3">
                                        <div className="flex items-center justify-between">
                                            <p className="font-semibold">{result.culprit.name}</p>
                                            {result.culprit.is_outdated && (
                                                <span className="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full">
                                                    Outdated
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-muted-foreground mt-1">
                                            Version {result.culprit.version}
                                            {result.culprit.is_outdated && ` → ${result.culprit.latest_version} available`}
                                        </p>
                                    </div>
                                )}

                                {result.theme_conflict && (
                                    <p className="text-sm text-red-700 mb-2">
                                        ⚠️ This conflict only occurs with your current theme.
                                    </p>
                                )}

                                {result.ai_analysis && (
                                    <div className="text-sm space-y-2">
                                        <p><strong>Diagnosis:</strong> {result.ai_analysis.diagnosis}</p>
                                        <p><strong>Recommendation:</strong> {result.ai_analysis.recommendation}</p>
                                    </div>
                                )}
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    className="flex-1"
                                    onClick={generateEmail}
                                >
                                    <Mail className="h-4 w-4 mr-2" />
                                    Generate Developer Email
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Close
                                </Button>
                            </div>
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
                                The issue persists even with all plugins disabled. This suggests a server configuration, WordPress core, or theme issue.
                            </p>
                            {result.ai_analysis && (
                                <p className="text-sm">{result.ai_analysis.recommendation}</p>
                            )}
                            <Button variant="outline" onClick={() => onOpenChange(false)}>Close</Button>
                        </div>
                    )}

                    {/* No Conflicts State */}
                    {status === 'clean' && (
                        <div className="text-center space-y-4 py-4">
                            <div className="mx-auto w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center">
                                <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                            </div>
                            <h3 className="font-bold text-lg">No Conflicts Found</h3>
                            <p className="text-muted-foreground">
                                All {result?.environment?.active_plugins_count || 0} plugins tested successfully.
                            </p>
                            <p className="text-sm text-muted-foreground">
                                If you're still experiencing issues, they may be caused by your theme, server configuration, or browser.
                            </p>
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
