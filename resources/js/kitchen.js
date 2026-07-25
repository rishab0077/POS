import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

const key = document.querySelector('meta[name="realtime-key"]')?.content;

if (key) {
    const port = Number(window.location.port || (window.location.protocol === "https:" ? 443 : 80));

    window.Echo = new Echo({
        broadcaster: "pusher",
        key,
        cluster: "mt1",
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: window.location.protocol === "https:",
        enabledTransports: ["ws", "wss"],
    });
}
