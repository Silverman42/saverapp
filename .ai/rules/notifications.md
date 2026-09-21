---
paths:
    - 'app/Notifications/**'
---

# Notifications

## Queueable Notifications Standard

All notifications in the application must implement Illuminate\Contracts\Queue\ShouldQueue and use Illuminate\Bus\Queueable to offload delivery to the queue worker.
