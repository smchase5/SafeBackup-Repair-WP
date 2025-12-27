import { useEffect, useState } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { useToast } from "@/components/ui/use-toast"
import { getSettings, saveSettings, type Settings } from "@/lib/api"
import { Loader2, ArrowLeft } from "lucide-react"

interface SettingsPageProps {
    onBack: () => void
}

export function SettingsPage({ onBack }: SettingsPageProps) {
    const [settings, setSettings] = useState<Settings>({ retention_limit: 5 })
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
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
            <Button variant="ghost" className="pl-0 gap-2" onClick={onBack}>
                <ArrowLeft className="h-4 w-4" />
                Back to Dashboard
            </Button>

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
        </div>
    )
}
