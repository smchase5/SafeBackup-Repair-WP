// Declare global variables for TS
declare global {
    interface Window {
        sbwpData: {
            root: string;
            nonce: string;
            restUrl: string;
            isPro: boolean;
        }
    }
}

export interface Stats {
    count: number;
    total_size: string;
    last_backup: string;
}

export interface Backup {
    id: number;
    created_at: string;
    type: string;
    storage_location: string;
    size_bytes: string;
    status: string;
}

export async function fetchStats(): Promise<Stats> {
    const response = await fetch(`${window.sbwpData.restUrl}/stats`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });
    if (!response.ok) throw new Error('Failed to fetch stats');
    return response.json();
}

export async function fetchBackups(): Promise<Backup[]> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups`, {
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to fetch backups');
    return response.json();
}

export async function deleteBackup(id: string): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups/${id}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': window.sbwpData.nonce }
    });
    if (!response.ok) throw new Error('Failed to delete backup');
    return response.json();
}


export async function fetchProgress(): Promise<{ percent: number, message: string }> {
    try {
        const response = await fetch(`${window.sbwpData.restUrl}/backup/progress`, {
            headers: { 'X-WP-Nonce': window.sbwpData.nonce }
        });
        if (!response.ok) return { percent: 0, message: 'Idle' };

        const text = await response.text();
        if (!text) return { percent: 0, message: 'Idle' };

        return JSON.parse(text);
    } catch (e) {
        return { percent: 0, message: 'Idle' };
    }
}

export interface CloudProvider {
    id: string;
    name: string;
    icon: string;
    connected: boolean;
}

export async function getCloudProviders(): Promise<CloudProvider[]> {
    const response = await fetch(`${window.sbwpData.restUrl}/cloud/providers`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });

    if (response.status === 404) return [];
    if (!response.ok) throw new Error('Failed to fetch cloud providers');
    return response.json();
}

export async function connectProvider(providerId: string, action: 'connect' | 'disconnect'): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/cloud/connect`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ provider_id: providerId, action })
    });
    if (!response.ok) throw new Error('Failed to update provider');
    return response.json();
}

export interface Settings {
    retention_limit: number;
}

export async function getSettings(): Promise<Settings> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings`, {
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce
        }
    });
    if (!response.ok) throw new Error('Failed to fetch settings');
    return response.json();
}

export async function saveSettings(settings: Settings): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/settings`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(settings)
    });
    if (!response.ok) throw new Error('Failed to save settings');
    return response.json();
}

export async function createBackup(resume = false): Promise<any> {
    const response = await fetch(`${window.sbwpData.restUrl}/backups`, {
        method: 'POST',
        headers: {
            'X-WP-Nonce': window.sbwpData.nonce,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ resume })
    });

    const text = await response.text();
    if (!text) {
        console.error(`SBWP Fetch Error: Empty response. Status: ${response.status} ${response.statusText}`);
        throw new Error(`Empty response from server (Status ${response.status})`);
    }

    try {
        const data = JSON.parse(text);
        if (!response.ok) throw new Error(data.message || 'Failed to create backup');
        return data;
    } catch (e) {
        console.error('JSON Parse Error:', text);
        throw new Error('Invalid server response');
    }
}
