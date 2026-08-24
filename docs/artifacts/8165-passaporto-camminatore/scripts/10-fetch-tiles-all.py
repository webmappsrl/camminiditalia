import json, math, base64, urllib.request, sys

SCRATCH = "/private/tmp/claude-501/-Users-bongiu-Documents-camminiditalia/78ade90d-3f9f-4f42-8dad-4e0e89d55dca/scratchpad"

def lonlat_to_world(lon, lat, z):
    n = 2 ** z
    x = (lon + 180.0) / 360.0 * n * 256
    lat_rad = math.radians(lat)
    y = (1 - math.log(math.tan(lat_rad) + 1/math.cos(lat_rad)) / math.pi) / 2 * n * 256
    return x, y

def bbox_of_coords(coords_list):
    minlon=180; maxlon=-180; minlat=90; maxlat=-90
    for coords in coords_list:
        for c in coords:
            lon, lat = c[0], c[1]
            minlon=min(minlon,lon); maxlon=max(maxlon,lon)
            minlat=min(minlat,lat); maxlat=max(maxlat,lat)
    return minlon, minlat, maxlon, maxlat

def pick_zoom(minlon, minlat, maxlon, maxlat, target_px=900, pad_frac=0.12):
    lon_span = maxlon - minlon
    lat_span = maxlat - minlat
    pad_lon = lon_span * pad_frac
    pad_lat = lat_span * pad_frac
    minlon -= pad_lon; maxlon += pad_lon
    minlat -= pad_lat; maxlat += pad_lat
    for z in range(18, 5, -1):
        x0, y0 = lonlat_to_world(minlon, maxlat, z)
        x1, y1 = lonlat_to_world(maxlon, minlat, z)
        w = x1 - x0
        h = y1 - y0
        if w <= target_px and h <= target_px:
            return z, (minlon, minlat, maxlon, maxlat)
    return 6, (minlon, minlat, maxlon, maxlat)

def fetch_tiles_for_bbox(name, minlon, minlat, maxlon, maxlat, target_px=1000, pad_frac=0.08):
    z, (minlon, minlat, maxlon, maxlat) = pick_zoom(minlon, minlat, maxlon, maxlat, target_px, pad_frac)

    # Bbox reso QUADRATO in spazio pixel (non solo il contenitore CSS): tracce
    # con forma molto allungata (es. tappa quasi rettilinea nord-sud) altrimenti
    # lascerebbero grandi bande vuote quando il contenitore viene forzato a un
    # rapporto d'aspetto 1:1 — espandendo il bbox stesso, le tile scaricate
    # riempiono davvero tutto il riquadro quadrato.
    x0, y0 = lonlat_to_world(minlon, maxlat, z)
    x1, y1 = lonlat_to_world(maxlon, minlat, z)
    w, h = x1 - x0, y1 - y0
    side = max(w, h)
    cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
    x0, x1 = cx - side / 2, cx + side / 2
    y0, y1 = cy - side / 2, cy + side / 2

    tx0 = int(x0 // 256)
    ty0 = int(y0 // 256)
    tx1 = int(x1 // 256)
    ty1 = int(y1 // 256)
    tiles = []
    for tx in range(tx0, tx1+1):
        for ty in range(ty0, ty1+1):
            url = f"https://api.webmapp.it/tiles/{z}/{tx}/{ty}.png"
            try:
                data = urllib.request.urlopen(url, timeout=15).read()
            except Exception as e:
                print(f"  WARN {url}: {e}", file=sys.stderr)
                data = b""
            b64 = base64.b64encode(data).decode('ascii') if data else ""
            tiles.append({"x": tx, "y": ty, "data": b64, "size": len(data)})
    print(f"{name}: zoom={z} tiles={len(tiles)}", file=sys.stderr)
    return {
        "zoom": z,
        "tileMinX": tx0, "tileMinY": ty0, "tileMaxX": tx1, "tileMaxY": ty1,
        "tiles": tiles
    }

all_tappe = json.load(open(f"{SCRATCH}/all_tappe_full.json"))
overview = json.load(open(f"{SCRATCH}/data_overview.json"))

result = {}
for t in all_tappe:
    key = f"tappa_{t['id']}"
    result[key] = fetch_tiles_for_bbox(key, *bbox_of_coords([t['geometry']['coordinates']]))

result["overview"] = fetch_tiles_for_bbox(
    "overview",
    *bbox_of_coords([t["geometry"]["coordinates"] for t in overview["tappe"]]),
    target_px=1100, pad_frac=0.1
)

json.dump(result, open(f"{SCRATCH}/tiles.json", "w"))
sizes = {k: sum(x["size"] for x in v["tiles"]) for k, v in result.items()}
print("sizes (bytes):", sizes, file=sys.stderr)
