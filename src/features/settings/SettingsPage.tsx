import { useEffect, useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/ui/use-toast"
import { getSettings, saveSettings, type Settings } from "@/lib/api"
import { Loader2, ArrowLeft } from "lucide-react"

import { CloudSettingsDialog } from "../dashboard/CloudSettingsDialog"

interface SettingsPageProps {
    onBack: () => void
    onNavigate: (view: 'dashboard' | 'settings' | 'schedules') => void
}

export function SettingsPage({ onBack, onNavigate }: SettingsPageProps) {
    const [settings, setSettings] = useState<Settings>({ retention_limit: 5 })
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [showCloudSettings, setShowCloudSettings] = useState(false)
    const { toast } = useToast()

    useEffect(() => {
        getSettings()
            .then(setSettings)
            .catch(console.error)
            .finally(() => setLoading(false))
    }, [])

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
        <div className="space-y-6 max-w-2xl">
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
        </div>
    )
}
