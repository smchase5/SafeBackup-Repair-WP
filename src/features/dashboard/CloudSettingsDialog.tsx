import { useEffect, useState } from "react"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { getCloudProviders, connectProvider, getCloudSettings, updateCloudSettings, type CloudProvider } from "@/lib/api"
import { Loader2, Cloud, ExternalLink, ChevronRight, ChevronDown } from "lucide-react"

interface CloudSettingsDialogProps {
    open: boolean
    onOpenChange: (open: boolean) => void
}

export function CloudSettingsDialog({ open, onOpenChange }: CloudSettingsDialogProps) {
    const [providers, setProviders] = useState<CloudProvider[]>([])
    const [loading, setLoading] = useState(false)
    const [processing, setProcessing] = useState<string | null>(null)
    const [expanded, setExpanded] = useState<string | null>(null)

    // GDrive Form State
    const [clientId, setClientId] = useState('')
    const [clientSecret, setClientSecret] = useState('')

    // S3 Form State
    const [s3Form, setS3Form] = useState({ accessKey: '', secretKey: '', region: '', bucket: '', endpoint: '' })

    // Global Settings
    const [retention, setRetention] = useState(5)

    const loadProviders = () => {
        setLoading(true)
        Promise.all([
            getCloudProviders(),
            getCloudSettings()
        ])
            .then(([p, s]) => {
                setProviders(p)
                if (s.retention_count) setRetention(s.retention_count)
            })
            .catch(console.error)
            .finally(() => setLoading(false))
    }

    useEffect(() => {
        if (open) {
            loadProviders()
        }
    }, [open])

    const handleDisconnect = async (providerId: string) => {
        setProcessing(providerId)
        try {
            await connectProvider(providerId, 'disconnect')
            loadProviders()
        } catch (error) {
            console.error(error)
        } finally {
            setProcessing(null)
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Cloud Storage Settings</DialogTitle>
                    <DialogDescription>
                        Connect Google Drive to enable automatic off-site backups.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-4">
                    <div className="flex items-center justify-between p-3 border rounded-lg bg-slate-50 dark:bg-slate-900/50">
                        <div className="space-y-0.5">
                            <Label className="text-sm font-medium">Retention Limit</Label>
                            <p className="text-xs text-muted-foreground">Number of backups to keep per provider.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Input
                                type="number"
                                min="1"
                                max="100"
                                className="w-20 text-right h-8"
                                value={retention}
                                onChange={(e) => {
                                    setRetention(parseInt(e.target.value) || 5)
                                    // Debounce save? Simple onBlur save is better.
                                }}
                                onBlur={() => updateCloudSettings({ retention_count: retention })}
                            />
                        </div>
                    </div>

                    {loading && providers.length === 0 ? (
                        <div className="flex justify-center p-4">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {providers.length === 0 && <p className="text-sm text-muted-foreground text-center">No providers available.</p>}

                            {providers.map(provider => (
                                <div key={provider.id} className="rounded-lg border p-3">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                                <Cloud className="h-5 w-5 text-slate-500" />
                                            </div>
                                            <div className="grid gap-0.5">
                                                <div className="font-medium text-sm">{provider.name}</div>
                                                <div className="text-[10px] text-muted-foreground uppercase flex items-center gap-1">
                                                    {provider.connected ? (
                                                        <span className="text-emerald-600 flex items-center gap-1">
                                                            Connected {provider.user_info?.email && `as ${provider.user_info.email}`}
                                                        </span>
                                                    ) : 'Not Connected'}
                                                </div>
                                            </div>
                                        </div>

                                        {provider.connected ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => handleDisconnect(provider.id)}
                                                disabled={processing === provider.id}
                                                className="text-red-500 hover:text-red-700 hover:bg-red-50"
                                            >
                                                {processing === provider.id ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Disconnect'}
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setExpanded(expanded === provider.id ? null : provider.id)}
                                            >
                                                {expanded === provider.id ? 'Cancel' : 'Setup'}
                                                {expanded === provider.id ? <ChevronDown className="ml-2 h-4 w-4" /> : <ChevronRight className="ml-2 h-4 w-4" />}
                                            </Button>
                                        )}
                                    </div>

                                    {/* Expandable Form for GDrive */}
                                    {expanded === provider.id && provider.id === 'gdrive' && !provider.connected && (
                                        <div className="mt-4 pt-4 border-t space-y-4 animate-in slide-in-from-top-2 fade-in duration-200">
                                            {/* ... GDrive Form Content ... */}
                                            {provider.global_creds ? (
                                                <div className="mb-4">
                                                    <div className="rounded-md bg-emerald-50 dark:bg-emerald-900/20 p-3 text-xs text-emerald-800 dark:text-emerald-200 flex items-center gap-2 mb-2">
                                                        <span className="font-semibold">✓ Ready to Connect</span>
                                                        <span>(Secure configuration active)</span>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        Click below to authorize this site to save backups to <strong>YOUR</strong> Google Drive account.
                                                    </p>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="rounded-md bg-blue-50 dark:bg-blue-900/20 p-3 text-xs text-blue-800 dark:text-blue-200">
                                                        <p className="font-semibold mb-1">Setup Instructions:</p>
                                                        <ol className="list-decimal pl-4 space-y-1">
                                                            <li>Create a project in <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener noreferrer" className="underline text-blue-600 dark:text-blue-400">Google Cloud Console</a>.</li>
                                                            <li>Add this <strong>Authorized Redirect URI</strong>:</li>
                                                        </ol>
                                                        <div className="mt-2 flex gap-2">
                                                            <code className="flex-1 bg-white dark:bg-black/20 p-1.5 rounded border font-mono text-[10px] break-all">
                                                                {window.sbwpData.root}wp-admin/admin-ajax.php?action=sbwp_oauth_callback
                                                            </code>
                                                        </div>
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label htmlFor="client_id">Client ID</Label>
                                                        <Input
                                                            id="client_id"
                                                            placeholder="xxxx.apps.googleusercontent.com"
                                                            value={clientId}
                                                            onChange={(e) => setClientId(e.target.value)}
                                                            className="font-mono text-xs"
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label htmlFor="client_secret">Client Secret</Label>
                                                        <Input
                                                            id="client_secret"
                                                            type="password"
                                                            placeholder="Client Secret"
                                                            value={clientSecret}
                                                            onChange={(e) => setClientSecret(e.target.value)}
                                                            className="font-mono text-xs"
                                                        />
                                                    </div>
                                                </>
                                            )}

                                            <Button
                                                className="w-full"
                                                onClick={async () => {
                                                    setProcessing('gdrive')
                                                    try {
                                                        // Call prepare - this saves credentials server-side
                                                        const result = await connectProvider('gdrive', 'prepare', {
                                                            client_id: clientId,
                                                            client_secret: clientSecret
                                                        })

                                                        console.log('SBWP: Prepare result:', result)

                                                        // If prepare succeeded, we can proceed. 
                                                        // If server didn't return auth_url (weird), construct it here as fallback.
                                                        let targetUrl = result.auth_url;

                                                        if (!targetUrl && result.success) {
                                                            console.warn('SBWP: Server missing auth_url, using fallback.');
                                                            const redirect_uri = encodeURIComponent(`${window.sbwpData.root}wp-admin/admin-ajax.php?action=sbwp_oauth_callback`)
                                                            const scope = encodeURIComponent('https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email')
                                                            targetUrl = `https://accounts.google.com/o/oauth2/v2/auth?client_id=${clientId}&redirect_uri=${redirect_uri}&response_type=code&scope=${scope}&access_type=offline&prompt=consent`
                                                        }

                                                        if (!targetUrl) {
                                                            throw new Error('Prepare failed - could not generate auth URL')
                                                        }

                                                        const popup = window.open(targetUrl, 'sbwp_auth', 'width=600,height=700')

                                                        const checkPopup = setInterval(() => {
                                                            if (popup?.closed) {
                                                                clearInterval(checkPopup)
                                                                loadProviders()
                                                                setProcessing(null)
                                                            }
                                                        }, 1000)

                                                        window.addEventListener('message', (event) => {
                                                            if (event.data?.type === 'sbwp_auth_success') {
                                                                clearInterval(checkPopup)
                                                                popup?.close()
                                                                loadProviders()
                                                                setProcessing(null)
                                                                setExpanded(null)
                                                            }
                                                        }, { once: true })

                                                    } catch (e: any) {
                                                        console.error('SBWP Auth Error:', e)
                                                        setProcessing(null)
                                                        alert(e.message || "Failed to initialize authentication.")
                                                    }
                                                }}
                                                disabled={processing === provider.id || (!provider.global_creds && (!clientId || !clientSecret))}
                                            >
                                                {processing === provider.id ? (
                                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                ) : (
                                                    <ExternalLink className="mr-2 h-4 w-4" />
                                                )}
                                                {provider.global_creds ? "Connect YOUR Google Drive" : "Connect Google Drive"}
                                            </Button>
                                        </div>
                                    )}

                                    {/* Generic S3 Form */}
                                    {expanded === provider.id && ['aws', 'do_spaces', 'wasabi'].includes(provider.id) && !provider.connected && (
                                        <div className="mt-4 pt-4 border-t space-y-4 animate-in slide-in-from-top-2 fade-in duration-200">
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label>Access Key</Label>
                                                    <Input
                                                        value={s3Form.accessKey}
                                                        onChange={(e) => setS3Form({ ...s3Form, accessKey: e.target.value })}
                                                        type="text" className="font-mono text-xs"
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Secret Key</Label>
                                                    <Input
                                                        value={s3Form.secretKey}
                                                        onChange={(e) => setS3Form({ ...s3Form, secretKey: e.target.value })}
                                                        type="password" className="font-mono text-xs"
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label>Bucket Name</Label>
                                                    <Input
                                                        value={s3Form.bucket}
                                                        onChange={(e) => setS3Form({ ...s3Form, bucket: e.target.value })}
                                                        type="text" className="font-mono text-xs"
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Region</Label>
                                                    <Input
                                                        value={s3Form.region}
                                                        onChange={(e) => setS3Form({ ...s3Form, region: e.target.value })}
                                                        placeholder={provider.id === 'do_spaces' ? 'nyc3' : 'us-east-1'}
                                                        type="text" className="font-mono text-xs"
                                                    />
                                                </div>
                                            </div>

                                            <Button
                                                className="w-full"
                                                disabled={processing === provider.id || !s3Form.accessKey || !s3Form.secretKey || !s3Form.bucket}
                                                onClick={async () => {
                                                    setProcessing(provider.id)
                                                    try {
                                                        await connectProvider(provider.id, 'connect', {
                                                            access_key: s3Form.accessKey,
                                                            secret_key: s3Form.secretKey,
                                                            bucket: s3Form.bucket,
                                                            region: s3Form.region || 'us-east-1',
                                                            endpoint: s3Form.endpoint
                                                        })
                                                        loadProviders()
                                                        setExpanded(null)
                                                        setS3Form({ accessKey: '', secretKey: '', region: '', bucket: '', endpoint: '' })
                                                    } catch (e: any) {
                                                        console.error(e)
                                                        alert(e.message || "Connection failed")
                                                    } finally {
                                                        setProcessing(null)
                                                    }
                                                }}
                                            >
                                                {processing === provider.id ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : "Connect Storage"}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    )
}
