import { useState, useEffect } from "react"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Trash2, HardDrive, Loader2, RotateCw, FolderOpen, Lock } from "lucide-react"
import { fetchBackups, deleteBackup, type Backup } from "@/lib/api"
import { useToast } from "@/components/ui/use-toast"
import { RestoreDialog } from "./RestoreDialog"
import { BackupBrowserDialog } from "./BackupBrowserDialog"

// Simple date formatter if date-fns not available, but let's assume raw string for now or standard JS Date
function formatDate(dateString: string) {
    if (!dateString) return 'Unknown';
    return new Date(dateString).toLocaleString();
}

// Helper for bytes
function formatBytes(bytes: string | number) {
    const b = typeof bytes === 'string' ? parseInt(bytes) : bytes;
    if (b === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(b) / Math.log(k));
    return parseFloat((b / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

interface BackupsListProps {
    refreshTrigger: number; // Increment to reload
}

export function BackupsList({ refreshTrigger }: BackupsListProps) {
    const [backups, setBackups] = useState<Backup[]>([])
    const [loading, setLoading] = useState(true)
    const [deletingId, setDeletingId] = useState<string | number | null>(null)
    const [selectedBackup, setSelectedBackup] = useState<Backup | null>(null)
    const [showRestoreDialog, setShowRestoreDialog] = useState(false)
    const [showBrowserDialog, setShowBrowserDialog] = useState(false)
    const { toast } = useToast()

    const loadBackups = async () => {
        setLoading(true)
        try {
            const data = await fetchBackups()
            console.log('SBWP: Backups loaded:', data)
            setBackups(data)
        } catch (e) {
            console.error(e)
        } finally {
            setLoading(false)
        }
    }

    useEffect(() => {
        loadBackups()
    }, [refreshTrigger])

    const handleDelete = async (id: number) => {
        if (!confirm("Permanently delete this backup?")) return

        setDeletingId(id)
        try {
            await deleteBackup(id.toString())
            toast({ title: "Backup Deleted", description: "The backup has been removed." })
            loadBackups() // Reload list
        } catch (error) {
            toast({ title: "Error", description: "Failed to delete backup.", variant: "destructive" })
        } finally {
            setDeletingId(null)
        }
    }

    const handleRestoreClick = (backup: Backup) => {
        setSelectedBackup(backup)
        setShowRestoreDialog(true)
    }

    const handleBrowseClick = (backup: Backup) => {
        setSelectedBackup(backup)
        setShowBrowserDialog(true)
    }

    return (
        <>
            <RestoreDialog
                backup={selectedBackup}
                open={showRestoreDialog}
                onOpenChange={setShowRestoreDialog}
                onComplete={loadBackups}
            />
            <BackupBrowserDialog
                backup={selectedBackup}
                open={showBrowserDialog}
                onOpenChange={setShowBrowserDialog}
            />
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Existing Backups</CardTitle>
                    <CardDescription>
                        Manage your local backup points.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <div className="flex justify-center p-4">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : backups.length === 0 ? (
                        <div className="text-center py-8 text-muted-foreground bg-muted/20 rounded-lg">
                            <HardDrive className="h-8 w-8 mx-auto mb-2 opacity-20" />
                            <p>No backups found.</p>
                        </div>
                    ) : (
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Size</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {backups.map((backup) => (
                                        <TableRow key={backup.id}>
                                            <TableCell className="font-medium">
                                                {formatDate(backup.created_at)}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                <span className="flex items-center gap-1">
                                                    {backup.type}
                                                    {Number(backup.is_locked) === 1 ? <span title="Locked - required for incremental restores"><Lock className="h-3 w-3 text-orange-500" /></span> : null}
                                                </span>
                                            </TableCell>
                                            <TableCell>{formatBytes(backup.size_bytes)}</TableCell>
                                            <TableCell className="text-right">
                                                <Button variant="ghost" size="icon" onClick={() => handleBrowseClick(backup)} title="Browse Files">
                                                    <FolderOpen className="h-4 w-4 text-yellow-600 hover:text-yellow-700" />
                                                </Button>
                                                <Button variant="ghost" size="icon" onClick={() => handleRestoreClick(backup)} title="Restore">
                                                    <RotateCw className="h-4 w-4 text-blue-500 hover:text-blue-700" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={deletingId === backup.id || Number(backup.is_locked) === 1}
                                                    onClick={() => handleDelete(backup.id)}
                                                    title={Number(backup.is_locked) === 1 ? "Locked - required for incremental restores" : "Delete"}
                                                >
                                                    {deletingId === backup.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className={`h-4 w-4 ${Number(backup.is_locked) === 1 ? 'text-gray-300' : 'text-red-500 hover:text-red-700'}`} />}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </>
    )
}
