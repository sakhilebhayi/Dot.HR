<div style="position:relative;" x-data @click.outside="$wire.open = false">
    <button wire:click="toggle" class="topbar-btn" title="Notifications" style="position:relative;">
        <span class="material-symbols-rounded">notifications</span>
        @if($this->unreadCount > 0)
            <span style="position:absolute;top:-2px;right:-2px;min-width:15px;height:15px;padding:0 3px;border-radius:100px;background:var(--rg-bad);color:#fff;font-size:9px;font-weight:600;display:flex;align-items:center;justify-content:center;line-height:1;">
                {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    @if($open)
    <div class="bell-panel" style="position:absolute;top:calc(100% + 8px);right:0;width:320px;max-height:420px;overflow-y:auto;background:var(--rg-ground);border:1px solid var(--rg-border);border-radius:10px;box-shadow:0 12px 32px rgba(31,32,29,0.14);z-index:50;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:0.85rem 1rem;border-bottom:1px solid var(--rg-rule);">
            <span style="font-size:12.5px;font-weight:600;color:var(--rg-ink);">Notifications</span>
            @if($this->unreadCount > 0)
                <button wire:click="markAllAsRead" style="background:none;border:none;color:var(--rg-accent);font-size:11px;font-weight:600;cursor:pointer;padding:0;">Mark all read</button>
            @endif
        </div>

        @if($this->notifications->isEmpty())
            <div style="text-align:center;padding:2rem 1rem;">
                <span class="material-symbols-rounded" style="font-size:30px;color:var(--rg-muted);display:block;margin-bottom:0.5rem;">notifications_off</span>
                <p style="font-size:0.75rem;color:var(--rg-muted);margin:0;">No notifications yet.</p>
            </div>
        @else
            <div>
                @foreach($this->notifications as $notification)
                <div role="button" tabindex="0" class="notif-row" wire:click="markAsRead('{{ $notification->id }}')" wire:keydown.enter="markAsRead('{{ $notification->id }}')" wire:keydown.space.prevent="markAsRead('{{ $notification->id }}')" style="padding:0.7rem 1rem;border-bottom:1px solid var(--rg-rule);cursor:pointer;{{ $notification->read_at ? 'opacity:0.55;' : '' }}">
                    <div style="display:flex;align-items:center;gap:6px;">
                        @if(!$notification->read_at)
                            <span style="width:6px;height:6px;border-radius:50%;background:var(--rg-accent);flex-shrink:0;"></span>
                        @endif
                        <span style="font-size:12px;font-weight:600;color:var(--rg-ink);">{{ $notification->data['title'] ?? 'Notification' }}</span>
                    </div>
                    <div style="font-size:11px;color:var(--rg-muted);margin-top:3px;line-height:1.4;">{{ $notification->data['message'] ?? '' }}</div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
    @endif
</div>
