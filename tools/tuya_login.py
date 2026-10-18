"""QR-Login wie Home Assistant, holt Local Key + Datenpunkte aller Geräte."""
import json, os, subprocess, sys, time
import qrcode
from tuya_sharing import LoginControl, Manager

CLIENT_ID = "HA_3y9q4ak7g4ephrvke"
SCHEMA = "haauthorize"
user_code = sys.argv[1]
out = sys.argv[2]

lc = LoginControl()
r = lc.qr_code(CLIENT_ID, SCHEMA, user_code)
if not r.get("success"):
    print("QR fehlgeschlagen:", r); sys.exit(1)
token = r["result"]["qrcode"]
png = os.path.join(out, "tuya_qr.png")
qrcode.make(f"tuyaSmart--qrLogin?token={token}").save(png)
subprocess.run(["open", png])
print("QR geöffnet, warte auf Scan …", flush=True)

for _ in range(100):
    time.sleep(3)
    ok, info = lc.login_result(token, CLIENT_ID, user_code)
    if ok:
        break
else:
    print("Kein Scan innerhalb von 5 Minuten"); sys.exit(2)

token_info = {k: info[k] for k in ("t", "uid", "expire_time", "access_token", "refresh_token")}
m = Manager(CLIENT_ID, user_code, info["terminal_id"], info["endpoint"], token_info, None)
m.update_device_cache()
devs = []
for d in m.device_map.values():
    devs.append({
        "id": d.id, "name": d.name, "local_key": d.local_key, "category": d.category,
        "product_id": d.product_id, "product_name": d.product_name, "ip": d.ip,
        "online": d.online, "status": d.status,
        "function": {k: {"type": v.type, "values": v.values} for k, v in d.function.items()},
        "status_range": {k: {"type": v.type, "values": v.values} for k, v in d.status_range.items()},
        "local_strategy": d.local_strategy,
    })
with open(os.path.join(out, "tuya_devices.json"), "w") as f:
    json.dump(devs, f, indent=1, ensure_ascii=False, default=str)
print(f"{len(devs)} Geräte gespeichert")
