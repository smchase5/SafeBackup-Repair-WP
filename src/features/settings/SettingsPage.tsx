import { useEffect, useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/ui/use-toast"
import { getSettings, saveSettings, getAISettings, saveAISettings, getRecoverySettings, saveRecoveryPin, regenerateRecoveryKey, type Settings, type AISettings, type RecoverySettings } from "@/lib/api"
import { Loader2, ArrowLeft, Sparkles, Check, X, Shield, Copy, RefreshCw, Key, Bell } from "lucide-react"
import { Switch } from "@/components/ui/switch"
import { copyToClipboard } from "@/lib/utils"

import { CloudSettingsDialog } from "../dashboard/CloudSettingsDialog"

interface SettingsPageProps {
    onBack: () => void
    onNavigate: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function SettingsPage({ onBack, onNavigate }: SettingsPageProps) {
    const [settings, setSettings] = useState<Settings>({ retention_limit: 5 })
    const [aiSettings, setAiSettings] = useState<AISettings | null>(null)
    const [recoverySettings, setRecoverySettings] = useState<RecoverySettings | null>(null)
    const [aiKeyInput, setAiKeyInput] = useState('')
    const [pinInput, setPinInput] = useState('')
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [savingAI, setSavingAI] = useState(false)
    const [savingRecovery, setSavingRecovery] = useState(false)
    const [showCloudSettings, setShowCloudSettings] = useState(false)
    const [copied, setCopied] = useState(false)
    const { toast } = useToast()

    useEffect(() => {
        Promise.all([
            getSettings().then(setSettings),
            window.sbwpData.isPro ? getAISettings().then(setAiSettings) : Promise.resolve(),
            getRecoverySettings().then(setRecoverySettings)
        ])
            .catch(console.error)
            .finally(() => setLoading(false))
    }, [])

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

    const handleSavePin = async () => {
        setSavingRecovery(true)
        try {
            const result = await saveRecoveryPin(pinInput)
            setRecoverySettings(result)
            setPinInput('')
            toast({ title: pinInput ? "PIN Set" : "PIN Removed", description: pinInput ? "Your recovery PIN has been saved." : "Your recovery PIN has been removed." })
        } catch (e) {
            toast({ title: "Error", description: "Failed to save PIN.", variant: "destructive" })
        } finally {
            setSavingRecovery(false)
        }
    }

    const handleRegenerateKey = async () => {
        if (!confirm('Are you sure? This will invalidate your old recovery URL.')) return
        setSavingRecovery(true)
        try {
            const result = await regenerateRecoveryKey()
            setRecoverySettings(result)
            toast({ title: "Key Regenerated", description: "Your recovery URL has been updated." })
        } catch (e) {
            toast({ title: "Error", description: "Failed to regenerate key.", variant: "destructive" })
        } finally {
            setSavingRecovery(false)
        }
    }

    const handleSaveAIKey = async () => {
        setSavingAI(true)
        try {
            const result = await saveAISettings(aiKeyInput)
            setAiSettings(result)
            setAiKeyInput('')
            toast({
                title: "API Key Saved",
                description: "Your OpenAI API key has been saved.",
            })
        } catch (error) {
            toast({
                title: "Error",
                description: "Failed to save API key.",
                variant: "destructive"
            })
        } finally {
            setSavingAI(false)
        }
    }

    const handleRemoveAIKey = async () => {
        setSavingAI(true)
        try {
            const result = await saveAISettings('')
            setAiSettings(result)
            toast({
                title: "API Key Removed",
                description: "Your OpenAI API key has been removed.",
            })
        } catch (error) {
            toast({
                title: "Error",
                description: "Failed to remove API key.",
                variant: "destructive"
            })
        } finally {
            setSavingAI(false)
        }
    }

    const handleSave = async () => {
        setSaving(true)
        try {
            await saveSettings(settings)
            toast({
                title: "Settings Saved",
                description: "Your settings have been updated.",
            })
        } catch (error) {
            toast({
                title: "Error",
                description: "Failed to save settings.",
                variant: "destructive"
            })
        } finally {
            setSaving(false)
        }
    }

    if (loading) {
        return <div className="p-8 flex justify-center"><Loader2 className="h-8 w-8 animate-spin text-muted-foreground" /></div>
    }

    return (
        <div className="space-y-6">
            <CloudSettingsDialog open={showCloudSettings} onOpenChange={setShowCloudSettings} />

            <Button variant="ghost" className="pl-0 gap-2" onClick={onBack}>
                <ArrowLeft className="h-4 w-4" />
                Back to Dashboard
            </Button>

            <h2 className="text-xl font-semibold tracking-tight">Configuration</h2>

            <Card>
                <CardHeader>
                    <CardTitle>General Settings</CardTitle>
                    <CardDescription>Configure how SafeBackup handles your data.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="retention">Local Backup Retention</Label>
                        <Input
                            id="retention"
                            type="number"
                            min={1}
                            max={50}
                            value={settings.retention_limit}
                            onChange={e => setSettings(s => ({ ...s, retention_limit: parseInt(e.target.value) || 1 }))}
                        />
                        <p className="text-xs text-muted-foreground">
                            Number of local backups to keep. Oldest backups will be deleted automatically when this limit is reached.
                        </p>
                    </div>

                    <Button onClick={handleSave} disabled={saving}>
                        {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Save Changes
                    </Button>
                </CardContent>
            </Card>

            <Card className={settings.alerts_enabled ? "border-red-500/50 bg-red-500/5" : ""}>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Bell className="h-5 w-5 text-red-500" />
                            <CardTitle>Crash Alerts</CardTitle>
                        </div>
                        {settings.alerts_enabled && (
                            <span className="text-[10px] bg-red-500/20 text-red-600 px-2 py-0.5 rounded-full font-bold">ACTIVE</span>
                        )}
                    </div>
                    <CardDescription>Get notified immediately if your site crashes.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex items-center justify-between space-x-2">
                        <Label htmlFor="alerts-enabled" className="flex flex-col space-y-1">
                            <span>Enable Email Alerts</span>
                            <span className="font-normal text-xs text-muted-foreground">
                                We'll send you a link to the Recovery Portal if a fatal error is detected.
                            </span>
                        </Label>
                        <Switch
                            id="alerts-enabled"
                            checked={settings.alerts_enabled || false}
                            onCheckedChange={checked => setSettings(s => ({ ...s, alerts_enabled: checked }))}
                        />
                    </div>

                    {settings.alerts_enabled && (
                        <div className="space-y-2 pt-2 border-t">
                            <Label htmlFor="alert-email">Notification Email</Label>
                            <Input
                                id="alert-email"
                                type="email"
                                placeholder="admin@example.com"
                                value={settings.alert_email || ''}
                                onChange={e => setSettings(s => ({ ...s, alert_email: e.target.value }))}
                            />
                        </div>
                    )}

                    <Button onClick={handleSave} disabled={saving} variant={settings.alerts_enabled ? "destructive" : "default"}>
                        {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Save Alert Settings
                    </Button>
                </CardContent>
            </Card>

            <div className="grid gap-4 md:grid-cols-2">
                <Card className={window.sbwpData.isPro ? "border-emerald-500/50 bg-emerald-500/5" : "opacity-80"}>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">
                            Cloud Storage
                        </CardTitle>
                        {window.sbwpData.isPro ? (
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold">PRO ACTIVE</span>
                        ) : (
                            <span className="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full font-bold">FREE</span>
                        )}
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">
                            {window.sbwpData.isPro ? 'Configure' : 'Locked'}
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                            {window.sbwpData.isPro
                                ? 'Connect to Google Drive / S3'
                                : 'Upgrade to enable Cloud Backups'}
                        </p>
                        {window.sbwpData.isPro ? (
                            <Button size="sm" variant="outline" className="mt-4 w-full h-8 text-xs" onClick={() => setShowCloudSettings(true)}>
                                Configure Cloud
                            </Button>
                        ) : (
                            <Button size="sm" variant="secondary" className="mt-4 w-full h-8 text-xs">
                                Get Pro
                            </Button>
                        )}
                    </CardContent>
                </Card>

                {window.sbwpData.isPro && (
                    <Card className="border-emerald-500/50 bg-emerald-500/5">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Scheduled Backups</CardTitle>
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold">PRO ACTIVE</span>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">Manage</div>
                            <p className="text-xs text-muted-foreground mt-1">
                                Automated daily/weekly backups
                            </p>
                            <Button size="sm" variant="outline" className="mt-4 w-full h-8 text-xs" onClick={() => onNavigate('schedules')}>
                                Configure Schedules
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* AI Assistant Settings */}
            <Card className={window.sbwpData.isPro ? "border-violet-500/50 bg-violet-500/5" : "opacity-80"}>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Sparkles className="h-5 w-5 text-violet-500" />
                            <CardTitle>AI Assistant</CardTitle>
                        </div>
                        {window.sbwpData.isPro ? (
                            aiSettings?.is_configured ? (
                                <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold flex items-center gap-1">
                                    <Check className="h-3 w-3" /> CONNECTED
                                </span>
                            ) : (
                                <span className="text-[10px] bg-amber-500/20 text-amber-600 px-2 py-0.5 rounded-full font-bold">NOT CONFIGURED</span>
                            )
                        ) : (
                            <span className="text-[10px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full font-bold">PRO</span>
                        )}
                    </div>
                    <CardDescription>
                        Use AI to explain update test results in plain language.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {window.sbwpData.isPro ? (
                        <>
                            {aiSettings?.is_configured ? (
                                <div className="flex items-center justify-between p-3 bg-slate-50 rounded-md">
                                    <div>
                                        <div className="text-sm font-medium">OpenAI API Key</div>
                                        <div className="text-xs text-muted-foreground font-mono">{aiSettings.masked_key}</div>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-red-500 hover:text-red-600 hover:bg-red-50"
                                        onClick={handleRemoveAIKey}
                                        disabled={savingAI}
                                    >
                                        {savingAI ? <Loader2 className="h-4 w-4 animate-spin" /> : <X className="h-4 w-4" />}
                                    </Button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <Label htmlFor="openai-key">OpenAI API Key</Label>
                                    <div className="flex gap-2">
                                        <Input
                                            id="openai-key"
                                            type="password"
                                            placeholder="sk-..."
                                            value={aiKeyInput}
                                            onChange={e => setAiKeyInput(e.target.value)}
                                        />
                                        <Button onClick={handleSaveAIKey} disabled={savingAI || !aiKeyInput}>
                                            {savingAI ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Save'}
                                        </Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer" className="text-violet-500 hover:underline">platform.openai.com</a>
                                    </p>
                                </div>
                            )}
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Upgrade to Pro to use AI-powered features like plain-language update reports.
                        </p>
                    )}
                </CardContent>
            </Card>

            {/* Recovery Portal Settings */}
            <Card className="border-orange-500/50 bg-orange-500/5">
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Shield className="h-5 w-5 text-orange-500" />
                            <CardTitle>Recovery Portal</CardTitle>
                        </div>
                        {recoverySettings?.has_pin && (
                            <span className="text-[10px] bg-emerald-500/20 text-emerald-500 px-2 py-0.5 rounded-full font-bold flex items-center gap-1">
                                <Key className="h-3 w-3" /> PIN PROTECTED
                            </span>
                        )}
                    </div>
                    <CardDescription>
                        Access this URL to recover your site if WordPress crashes.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {/* Recovery URL */}
                    <div className="space-y-2">
                        <Label>Recovery URL</Label>
                        <div className="flex gap-2">
                            <Input
                                value={recoverySettings?.url || ''}
                                readOnly
                                className="font-mono text-xs bg-slate-50"
                            />
                            <Button variant="outline" size="icon" onClick={handleCopyUrl}>
                                {copied ? <Check className="h-4 w-4 text-emerald-500" /> : <Copy className="h-4 w-4" />}
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Save this URL somewhere safe! It works even when WordPress is broken.
                        </p>
                    </div>

                    {/* PIN Management */}
                    <div className="space-y-2 pt-2 border-t">
                        <Label>{recoverySettings?.has_pin ? 'Change or Remove PIN' : 'Set Optional PIN'}</Label>
                        <div className="flex gap-2">
                            <Input
                                type="password"
                                placeholder={recoverySettings?.has_pin ? "New PIN (or leave empty to remove)" : "Enter a PIN (optional)"}
                                value={pinInput}
                                onChange={e => setPinInput(e.target.value)}
                            />
                            <Button onClick={handleSavePin} disabled={savingRecovery} variant={recoverySettings?.has_pin && !pinInput ? "destructive" : "default"}>
                                {savingRecovery ? <Loader2 className="h-4 w-4 animate-spin" /> : (recoverySettings?.has_pin && !pinInput ? 'Remove' : 'Save')}
                            </Button>
                        </div>
                    </div>

                    {/* Regenerate Key */}
                    <div className="pt-2 border-t">
                        <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={handleRegenerateKey} disabled={savingRecovery}>
                            <RefreshCw className="h-3 w-3 mr-2" />
                            Regenerate Recovery Key
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    )

}
