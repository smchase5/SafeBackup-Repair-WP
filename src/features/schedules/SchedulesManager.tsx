import { useState, useEffect } from 'react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { CalendarClock, Trash2, Plus, Loader2, ArrowLeft } from "lucide-react"
import { useToast } from "@/components/ui/use-toast"

interface Schedule {
    id: number
    frequency: string
    time: string
    created_at: string
}

interface SchedulesManagerProps {
    onBack: () => void
}

export function SchedulesManager({ onBack }: SchedulesManagerProps) {
    const [schedules, setSchedules] = useState<Schedule[]>([])
    const [loading, setLoading] = useState(false)
    const [creating, setCreating] = useState(false)
    const { toast } = useToast()

    const fetchSchedules = async () => {
        setLoading(true)
        try {
            const res = await fetch(`${window.sbwpData.restUrl}/schedules`, {
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            const data = await res.json()
            setSchedules(data)
        } catch (e) {
            console.error(e)
        } finally {
            setLoading(false)
        }
    }

    useEffect(() => {
        fetchSchedules()
    }, [])

    const handleCreate = async (frequency: string) => {
        setCreating(true)
        try {
            const res = await fetch(`${window.sbwpData.restUrl}/schedules`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': window.sbwpData.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ frequency, time: '00:00' })
            })
            if (res.ok) {
                toast({ title: "Schedule Created", description: `New ${frequency} schedule added.` })
                fetchSchedules()
            }
        } catch (e) {
            toast({ title: "Error", variant: "destructive", description: "Failed to create schedule." })
        } finally {
            setCreating(false)
        }
    }

    const handleDelete = async (id: number) => {
        if (!confirm("Delete this schedule?")) return
        try {
            await fetch(`${window.sbwpData.restUrl}/schedules/${id}`, {
                method: 'DELETE',
                headers: { 'X-WP-Nonce': window.sbwpData.nonce }
            })
            toast({ title: "Schedule Deleted" })
            fetchSchedules()
        } catch (e) {
            toast({ title: "Error", variant: "destructive" })
        }
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center gap-4">
                <Button variant="ghost" size="sm" onClick={onBack}>
                    <ArrowLeft className="mr-2 h-4 w-4" />
                    Back
                </Button>
                <div>
                    <h2 className="text-3xl font-bold tracking-tight">Scheduled Backups</h2>
                    <p className="text-muted-foreground">Manage your automated backup routines.</p>
                </div>
            </div>

            <div className="grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Create Schedule</CardTitle>
                        <CardDescription>Add a new automated backup task.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Button className="w-full justify-start" onClick={() => handleCreate('daily')} disabled={creating}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Daily Backup
                        </Button>
                        <Button className="w-full justify-start" variant="secondary" onClick={() => handleCreate('weekly')} disabled={creating}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Weekly Backup
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Active Schedules</CardTitle>
                        <CardDescription>Your currently running backup jobs.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {loading ? (
                            <div className="flex justify-center p-4">
                                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                            </div>
                        ) : schedules.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No active schedules.</p>
                        ) : (
                            <div className="space-y-2">
                                {schedules.map(schedule => (
                                    <div key={schedule.id} className="flex items-center justify-between p-3 border rounded-lg">
                                        <div className="flex items-center gap-3">
                                            <CalendarClock className="h-5 w-5 text-blue-500" />
                                            <div>
                                                <div className="font-medium capitalize">{schedule.frequency} Backup</div>
                                                <div className="text-xs text-muted-foreground">Next run: calculated by WP Cron</div>
                                            </div>
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => handleDelete(schedule.id)}>
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    )
}
