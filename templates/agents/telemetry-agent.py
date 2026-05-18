import subprocess, json, urllib.request, time, sys

TOKEN = "{{ TOKEN }}"
URL = "{{ URL }}"

interval = 15

while True:
    try:
        container = "nk-awg-v2"
        res = subprocess.run(["docker", "exec", container, "/usr/local/bin/awg", "show", "all", "dump"], capture_output=True, text=True)
        if res.returncode != 0:
            container = "amnezia-wg"
            res = subprocess.run(["docker", "exec", container, "/usr/local/bin/awg", "show", "all", "dump"], capture_output=True, text=True)
            
        dump = res.stdout.strip()
        peers = []
        
        if dump:
            for line in dump.split("\n"):
                parts = line.split("\t")
                if len(parts) >= 8:
                    peers.append({
                        "public_key": parts[1],
                        "bytes_sent": int(parts[6]),
                        "bytes_received": int(parts[7]),
                        "latest_handshake": int(parts[5]),
                        "endpoint_ip": parts[3].split(":")[0] if ":" in parts[3] else (None if parts[3] == "(none)" else parts[3])
                    })
        
        payload = {
            "timestamp": int(time.time()),
            "peers": peers
        }
        
        req = urllib.request.Request(
            URL,
            data=json.dumps(payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {TOKEN}",
                "X-Telemetry-Token": TOKEN
            },
            method="POST"
        )
        
        import ssl
        context = ssl._create_unverified_context()
        with urllib.request.urlopen(req, timeout=10, context=context) as f:
            resp = f.read().decode().strip()
            if resp.isdigit():
                interval = int(resp)
            else:
                interval = 15
            sys.stdout.write(f"[{time.strftime('%H:%M:%S')}] Telemetry pushed successfully. Interval: {interval}s\n")
            sys.stdout.flush()
                
    except Exception as e:
        sys.stderr.write(f"[{time.strftime('%H:%M:%S')}] Error: {str(e)}\n")
        sys.stderr.flush()
        interval = 15
        
    time.sleep(interval)
