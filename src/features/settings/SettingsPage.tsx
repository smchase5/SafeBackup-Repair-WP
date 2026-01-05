import { useEffect, useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/ui/use-toast"
import { getSettings, saveSettings, getAISettings, saveAISettings, getRecoverySettings, saveRecoveryPin, regenerateRecoveryKey, type Settings, type AISettings, type RecoverySettings } from "@/lib/api"
import { Loader2, ArrowLeft, Sparkles, Check, X, Shield, Copy, RefreshCw, Key, Bell, Save, Cloud, Calendar, Settings as SettingsIcon } from "lucide-react"
import { Switch } from "@/components/ui/switch"
import { Separator } from "@/components/ui/separator"
import { copyToClipboard } from "@/lib/utils"

import { CloudSettingsDialog } from "../dashboard/CloudSettingsDialog"

interface SettingsPageProps {
    onBack: () => void
    onNavigate: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function SettingsPage({ onBack, onNavigate }: SettingsPageProps) {
    // State
    const [settings, setSettings] = useState<Settings>({ retention_limit: 5 })
    const [aiSettings, setAiSettings] = useState<AISettings | null>(null)
    const [recoverySettings, setRecoverySettings] = useState<RecoverySettings | null>(null)

    // Form Inputs
    const [aiKeyInput, setAiKeyInput] = useState('')
    const [pinInput, setPinInput] = useState('')

    // UI State
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [showCloudSettings, setShowCloudSettings] = useState(false)
    const [copied, setCopied] = useState(false)
    const { toast } = useToast()

    const isPro = window.sbwpData.isPro

    useEffect(() => {
        const loadData = async () => {
            try {
                const [s, r] = await Promise.all([
                    getSettings(),
                    getRecoverySettings()
                ])
                setSettings(s)
                setRecoverySettings(r)

                if (isPro) {
                    const ai = await getAISettings()
                    setAiSettings(ai)
                }
            } catch (e) {
                console.error(e)
                toast({ title: "Error", description: "Failed to load settings.", variant: "destructive" })
            } finally {
                setLoading(false)
            }
        }
        loadData()
    }, [isPro, toast])

    const handleCopyUrl = async () => {
        if (recoverySettings?.url) {
            const success = await copyToClipboard(recoverySettings.url)
            if (success) {
                setCopied(true)
                setTimeout(() => setCopied(false), 2000)
                toast({ title: "Copied!", description: "Recovery URL copied to clipboard." })
            } else {
                toast({ title: "Copy Failed", description: "Please copy the URL manually.", variant: "destructive" })
            }
        }
    }

    const handleRegenerateKey = async () => {
        if (!confirm('Are you sure? This will invalidate your old recovery URL.')) return
        setSaving(true)
        try {
            const result = await regenerateRecoveryKey()
            setRecoverySettings(result)
            toast({ title: "Key Regenerated", description: "Your recovery URL has been updated." })
        } catch (e) {
            toast({ title: "Error", description: "Failed to regenerate key.", variant: "destructive" })
        } finally {
            setSaving(false)
        }
    }

    const handleRemoveAIKey = async () => {
        if (!confirm('Remove OpenAI API Key?')) return
        setSaving(true)
        try {
            const result = await saveAISettings('')
            setAiSettings(result)
            setAiKeyInput('')
            toast({ title: "API Key Removed" })
        } catch (error) {
            toast({ title: "Error", description: "Failed to remove API key.", variant: "destructive" })
        } finally {
            setSaving(false)
        }
    }

    const handleSaveAll = async () => {
        setSaving(true)
        const promises = []

        // 1. General Settings
        promises.push(saveSettings(settings))

        // 2. Recovery PIN (if changed)
        if (pinInput) {
            promises.push(saveRecoveryPin(pinInput).then(r => {
                setRecoverySettings(r)
                setPinInput('') // Clear after save
                return r
            }))
        } else if (pinInput === '' && recoverySettings?.has_pin === false) {
            // Nothing to do if empty and already no pin
        }

        // 3. AI Settings (if Pro and input exists)
        if (isPro && aiKeyInput) {
            promises.push(saveAISettings(aiKeyInput).then(a => {
                setAiSettings(a)
                setAiKeyInput('') // Clear
                return a
            }))
        }

        try {
            await Promise.all(promises)
            toast({ title: "Settings Saved", description: "All changes have been applied successfully." })
        } catch (error) {
            console.error(error)
            toast({ title: "Error", description: "Some settings failed to save.", variant: "destructive" })
        } finally {
            setSaving(false)
        }
    }

    if (loading) {
        return <div className="p-8 flex justify-center"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
    }

    return (
        <div className="space-y-8 pb-24">
            <CloudSettingsDialog open={showCloudSettings} onOpenChange={setShowCloudSettings} />

            {/* Page Header */}
            <div className="space-y-1">
                <Button variant="ghost" className="pl-0 gap-2 text-muted-foreground hover:text-foreground -ml-2 mb-2" onClick={onBack}>
                    <ArrowLeft className="h-4 w-4" />
                    Back to Dashboard
                </Button>
                <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                        <SettingsIcon className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                        <p className="text-sm text-muted-foreground">Configure backup preferences and security options</p>
                    </div>
                </div>
            </div>

            {/* General Settings Section */}
            <section className="space-y-4">
                <div className="flex items-center gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wide">General</h2>
                    <Separator className="flex-1" />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Backup Retention */}
                    <Card>
                        <CardHeader className="pb-4">
                            <CardTitle className="text-base">Backup Retention</CardTitle>
                            <CardDescription>
                                Number of recent backups to keep on the server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-4">
                                <Input
                                    id="retention"
                                    type="number"
                                    min={1}
                                    max={50}
                                    className="w-24"
                                    value={settings.retention_limit}
                                    onChange={e => setSettings(s => ({ ...s, retention_limit: parseInt(e.target.value) || 1 }))}
                                />
                                <span className="text-sm text-muted-foreground">backups</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Crash Alerts */}
                    <Card className={settings.alerts_enabled ? "ring-1 ring-red-500/20 bg-red-500/5" : ""}>
                        <CardHeader className="pb-4">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Bell className="h-4 w-4" />
                                    Crash Alerts
                                </CardTitle>
                                <Switch
                                    id="alerts-enabled"
                                    checked={settings.alerts_enabled || false}
                                    onCheckedChange={checked => setSettings(s => ({ ...s, alerts_enabled: checked }))}
                                />
                            </div>
                            <CardDescription>
                                Receive an email with a recovery link if your site crashes.
                            </CardDescription>
                        </CardHeader>
                        {settings.alerts_enabled && (
                            <CardContent className="pt-0">
                                <div className="space-y-2">
                                    <Label htmlFor="alert-email" className="text-sm">Notification Email</Label>
                                    <Input
                                        id="alert-email"
                                        type="email"
                                        placeholder="admin@example.com"
                                        value={settings.alert_email || ''}
                                        onChange={e => setSettings(s => ({ ...s, alert_email: e.target.value }))}
                                    />
                                </div>
                            </CardContent>
                        )}
                    </Card>
                </div>
            </section>

            {/* Recovery Portal Section */}
            <section className="space-y-4">
                <div className="flex items-center gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wide">Security</h2>
                    <Separator className="flex-1" />
                </div>

                <Card className="ring-1 ring-orange-500/20 bg-gradient-to-br from-orange-500/5 to-transparent">
                    <CardHeader className="pb-4">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-lg flex items-center gap-2">
                                <Shield className="h-5 w-5 text-orange-600" />
                                Recovery Portal
                            </CardTitle>
                            {recoverySettings?.has_pin && (
                                <span className="text-xs bg-emerald-500/10 text-emerald-600 px-2.5 py-1 rounded-full font-medium flex items-center gap-1.5 border border-emerald-500/20">
                                    <Key className="h-3 w-3" />
                                    PIN Protected
                                </span>
                            )}
                        </div>
                        <CardDescription>
                            Emergency access to your site when WordPress is down.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {/* Recovery URL */}
                        <div className="space-y-2">
                            <Label className="text-sm">Recovery URL</Label>
                            <div className="flex gap-2">
                                <div className="flex-1 flex items-center gap-2 bg-muted/50 p-3 rounded-md border">
                                    <code className="flex-1 text-xs font-mono truncate text-muted-foreground">
                                        {recoverySettings?.url}
                                    </code>
                                    <Button variant="ghost" size="icon" className="h-7 w-7 shrink-0" onClick={handleCopyUrl}>
                                        {copied ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
                                    </Button>
                                </div>
                                <Button variant="outline" size="sm" className="shrink-0 text-muted-foreground hover:text-destructive" onClick={handleRegenerateKey}>
                                    <RefreshCw className="h-3.5 w-3.5 mr-2" />
                                    Reset
                                </Button>
                            </div>
                        </div>

                        {/* PIN Setting */}
                        <div className="space-y-2 max-w-sm">
                            <Label className="text-sm">{recoverySettings?.has_pin ? 'Update PIN' : 'Set PIN (Optional)'}</Label>
                            <Input
                                type="password"
                                placeholder={recoverySettings?.has_pin ? "Enter new PIN to change" : "Enter PIN to protect portal"}
                                value={pinInput}
                                onChange={e => setPinInput(e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                {recoverySettings?.has_pin ? 'Leave blank to keep your current PIN.' : 'Add an extra layer of security to your recovery portal.'}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </section>

            {/* Pro Features Section */}
            <section className="space-y-4">
                <div className="flex items-center gap-2">
                    <h2 className="text-sm font-medium text-muted-foreground uppercase tracking-wide">Pro Features</h2>
                    <Separator className="flex-1" />
                    {!isPro && (
                        <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-gradient-to-r from-violet-500/10 to-purple-500/10 text-violet-600 border border-violet-500/20">
                            Upgrade to Pro
                        </span>
                    )}
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {/* Cloud Storage */}
                    <Card className={!isPro ? "opacity-60" : ""}>
                        <CardHeader className="pb-4">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Cloud className="h-4 w-4" />
                                    Cloud Storage
                                </CardTitle>
                                {!isPro && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">PRO</span>}
                            </div>
                            <CardDescription>
                                Sync backups to Google Drive or AWS S3.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                variant={isPro ? "outline" : "secondary"}
                                size="sm"
                                className="w-full"
                                onClick={() => isPro && setShowCloudSettings(true)}
                                disabled={!isPro}
                            >
                                {isPro ? 'Configure Cloud' : 'Requires Pro'}
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Schedules */}
                    <Card className={!isPro ? "opacity-60" : ""}>
                        <CardHeader className="pb-4">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Calendar className="h-4 w-4" />
                                    Schedules
                                </CardTitle>
                                {!isPro && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">PRO</span>}
                            </div>
                            <CardDescription>
                                Automated daily or weekly backups.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                variant={isPro ? "outline" : "secondary"}
                                size="sm"
                                className="w-full"
                                onClick={() => isPro && onNavigate('schedules')}
                                disabled={!isPro}
                            >
                                {isPro ? 'Manage Schedules' : 'Requires Pro'}
                            </Button>
                        </CardContent>
                    </Card>

                    {/* AI Assistant */}
                    <Card className={isPro ? (aiSettings?.is_configured ? "ring-1 ring-violet-500/20 bg-violet-500/5" : "") : "opacity-60"}>
                        <CardHeader className="pb-4">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Sparkles className="h-4 w-4 text-violet-500" />
                                    AI Assistant
                                </CardTitle>
                                {!isPro && <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">PRO</span>}
                                {isPro && aiSettings?.is_configured && (
                                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-600">ACTIVE</span>
                                )}
                            </div>
                            <CardDescription>
                                AI-powered crash analysis and explanations.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {isPro ? (
                                aiSettings?.is_configured ? (
                                    <div className="flex items-center justify-between p-2.5 bg-muted/50 rounded-md border">
                                        <span className="font-mono text-xs text-muted-foreground">{aiSettings.masked_key}</span>
                                        <Button variant="ghost" size="icon" className="h-6 w-6 text-muted-foreground hover:text-destructive" onClick={handleRemoveAIKey}>
                                            <X className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <Input
                                            type="password"
                                            className="font-mono text-xs"
                                            placeholder="sk-..."
                                            value={aiKeyInput}
                                            onChange={e => setAiKeyInput(e.target.value)}
                                        />
                                        <p className="text-[10px] text-muted-foreground text-right">
                                            Enter your OpenAI API Key
                                        </p>
                                    </div>
                                )
                            ) : (
                                <Button variant="secondary" size="sm" className="w-full" disabled>
                                    Requires Pro
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </section>

            {/* Floating Save Button */}
            <div className="fixed bottom-6 right-6 z-50">
                <Button
                    size="lg"
                    onClick={handleSaveAll}
                    disabled={saving}
                    className="shadow-xl rounded-full px-6 hover:scale-105 transition-transform"
                >
                    {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    <Save className="mr-2 h-4 w-4" />
                    Save Changes
                </Button>
            </div>
        </div>
    )
}
