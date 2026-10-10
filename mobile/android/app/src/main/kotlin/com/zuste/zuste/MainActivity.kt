package com.zuste.zuste

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        createDefaultNotificationChannel()
    }

    /**
     * The channel every push names (channel_id "zuste_default", set by the
     * backend and in the manifest). Firebase does not create a channel it is
     * told to use; when the channel is missing it falls back to its own
     * "Miscellaneous" one. Creating an existing channel is a no-op, and the
     * user's own settings for it are kept.
     */
    private fun createDefaultNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val manager = getSystemService(NotificationManager::class.java) ?: return
        val channel = NotificationChannel(
            DEFAULT_CHANNEL_ID,
            "Notifications",
            NotificationManager.IMPORTANCE_HIGH,
        )
        channel.description = "Bookings, messages, payments and account updates"
        manager.createNotificationChannel(channel)
    }

    private companion object {
        const val DEFAULT_CHANNEL_ID = "zuste_default"
    }
}
