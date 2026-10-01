"""
Does a printed label actually scan?

The MITO mark sits in the middle of the code, which is an occluder: every module
it covers is a module a scanner cannot read. The claim that this is safe rests on
error correction H recovering roughly 30% against a 20% plate, which until now was
arithmetic on paper rather than a measurement.

The matrix comes from the project's own encoder (tools/qr-matrix-probe.php), so what
is rasterised here is the real code the service emits - not a re-encode of the
payload. The plate and the mark are then applied exactly as AssetQrCode places
them, and OpenCV's detector is asked to read the result back.

Run it from the project root:

    python tools/verify-qr-scannability.py

Re-run it after changing error_correction or logo_ratio in config/assets.php -
those two numbers are a measured tradeoff, and this is what measures them.
"""

import json
import os
import subprocess
import sys

import cv2
import numpy as np
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROBE = ["php", os.path.join(ROOT, "tools", "qr-matrix-probe.php")]
LOGO = os.path.join(ROOT, "public", "images", "mito-icon.png")


def matrix(url, ec, margin):
    """Ask the project's encoder for the module grid it would actually draw."""
    out = subprocess.run(
        PROBE + [url, ec, str(margin)],
        capture_output=True, text=True, cwd=ROOT,
    )
    if out.returncode != 0:
        raise SystemExit("php probe failed: " + out.stderr[-600:])

    payload = json.loads(out.stdout)
    rows = payload["rows"]

    return [[1 if c == "1" else 0 for c in row] for row in rows], payload


def rasterise(grid, margin, scale, ratio, with_mark):
    """
    Draw the label the way a print pipeline would.

    Mirrors AssetQrCode: a quiet zone of `margin` modules, then the code, then a
    white plate of `ratio` of the code's width with the mark centred on it.
    """
    modules = len(grid)
    total = modules + margin * 2
    size = total * scale
    img = np.full((size, size), 255, np.uint8)

    for y, row in enumerate(grid):
        for x, bit in enumerate(row):
            if bit:
                px = (margin + x) * scale
                py = (margin + y) * scale
                img[py:py + scale, px:px + scale] = 0

    if ratio and ratio > 0:
        plate = int(round(total * ratio))
        off = (total - plate) // 2

        # The plate wipes the code beneath it.
        img[off * scale:(off + plate) * scale, off * scale:(off + plate) * scale] = 255

        if with_mark:
            pad = max(2, int(round(plate * 0.12)))
            inner = max(1, plate - pad * 2)
            mark = Image.open(LOGO).convert("RGBA").resize(
                (inner * scale, inner * scale), Image.LANCZOS
            )
            y0 = (off + pad) * scale
            x0 = (off + pad) * scale
            h = inner * scale
            base = Image.fromarray(img[y0:y0 + h, x0:x0 + h]).convert("RGBA")
            base.alpha_composite(mark)
            img[y0:y0 + h, x0:x0 + h] = np.array(base.convert("RGB"))[:, :, 0]

    return img


def degrade(img, blur=0.0, noise=0.0, angle=0.0, scale=1.0, seed=7):
    """
    Approximate what a sticker actually goes through before a phone sees it.

    A digital render is the easy case. This is the claim that matters - "scans on a
    scuffed sticker" - so it is worth approximating: camera softness, sensor
    noise, the angle a label is held at, and ink spread on cheap printing.

    Rotation pads first. Turning a square image by 20 degrees without padding
    shaves its corners off, which crops the outer finder patterns and fails the
    decode for reasons that have nothing to do with the label - a real photo of a
    tilted sticker shows the whole thing.
    """
    rng = np.random.default_rng(seed)
    out = img

    if scale != 1.0:
        h, w = out.shape
        out = cv2.resize(out, (max(8, int(w * scale)), max(8, int(h * scale))),
                         interpolation=cv2.INTER_AREA)

    if angle:
        h, w = out.shape
        pad = int(max(h, w) * (abs(np.sin(np.radians(angle))) + abs(np.cos(np.radians(angle)))) / 2)
        out = cv2.copyMakeBorder(out, pad, pad, pad, pad, cv2.BORDER_CONSTANT, value=255)
        h, w = out.shape
        m = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
        out = cv2.warpAffine(out, m, (w, h), borderValue=255)

    if blur > 0:
        out = cv2.GaussianBlur(out, (int(blur) | 1, int(blur) | 1), 0)

    if noise > 0:
        out = np.clip(out.astype(np.float32) + rng.normal(0, noise * 255, out.shape), 0, 255)
        out = out.astype(np.uint8)

    return out


def decode(img):
    data, points, _ = cv2.QRCodeDetector().detectAndDecode(img)
    return data


def main():
    url = "https://assets.mito.co.id/assets/1"
    margin = 2
    scale = 8

    print(f"code: {url}\n")

    grid, meta = matrix(url, "H", margin)
    modules = meta["modules"]
    print(f"version 4-ish grid: {modules}x{modules} modules, EC {meta['ec']}, margin {margin}")
    print(f"printed at {modules * scale / 96:.2f} in wide on paper\n")

    results = []

    # Control: no plate at all. If this fails the harness is wrong, not the design.
    bare = rasterise(grid, margin, scale, 0, False)
    ok = decode(bare) == url
    results.append(ok)
    print(f"{'bare code (harness control)':<44} {'SCANS' if ok else 'HARNESS BROKEN'}")

    for ratio, ec in [(0.20, "H"), (0.25, "H"), (0.30, "H"), (0.20, "Q"), (0.20, "M")]:
        g, _ = matrix(url, ec, margin)
        for with_mark in (True, False):
            img = rasterise(g, margin, scale, ratio, with_mark)
            ok = decode(img) == url
            results.append(ok)
            kind = "with mark" if with_mark else "plate only"
            label = f"EC {ec}, plate {int(ratio * 100)}% ({kind})"
            print(f"{label:<44} {'SCANS' if ok else 'FAILS'}")

    print()
    print(f"bare control plus {len(results) - 1} cases: "
          f"{sum(results)}/{len(results)} decoded")

    print("\n--- is the harness capable of failing? ---")
    print("(a decoder that reads everything is not evidence of anything)")

    # A plate wide enough to swallow a finder pattern must break it.
    fatal = []
    for ratio in (0.50, 0.60):
        img = rasterise(grid, margin, scale, ratio, True)
        ok = decode(img) == url
        fatal.append(not ok)
        print(f"{'EC H, plate ' + str(int(ratio * 100)) + '% (finder buried)':<44} "
              f"{'SCANS' if ok else 'FAILS (expected)'}")

    # A truncated image must not decode either.
    half = rasterise(grid, margin, scale, 0.20, True)
    cropped = half[:, : int(half.shape[1] * 0.6)]
    ok = decode(cropped) == url
    fatal.append(not ok)
    print(f"{'cropped to 60% width':<44} {'SCANS' if ok else 'FAILS (expected)'}")

    print()
    if all(fatal):
        print("harness validated: it rejects broken codes, so the passes above mean something")
    else:
        print("HARNESS NOT TRUSTWORTHY: it decoded a code it should not have")

    print("\n--- a real sticker is not a clean render ---")
    print("phone camera, at an angle, in bad light, printed small")
    print()

    for ratio, ec, name in ((0.20, "H", "shipped: H + 20% plate"), (0.20, "M", "M + 20% (no H)")):
        g, _ = matrix(url, ec, margin)
        base = rasterise(g, margin, scale, ratio, True)

        # Control: the same degradations on a bare code. Any failure both suffer is
        # the harness or the decoder, not the plate.
        control = rasterise(g, margin, scale, 0, False)

        print(f"{name}")
        for label, kw in [
            ("photographed small (600px)", dict(scale=0.55)),
            ("tilted 20 degrees", dict(angle=20)),
            ("tilted 35 degrees", dict(angle=35)),
            ("soft focus (blur 9)", dict(blur=9)),
            ("low light (noise 12%)", dict(noise=0.12)),
            ("small + tilted + soft", dict(scale=0.55, angle=20, blur=9)),
            ("all four together", dict(scale=0.55, angle=20, blur=9, noise=0.12)),
        ]:
            ok = decode(degrade(base, **kw)) == url
            base_ok = decode(degrade(control, **kw)) == url
            verdict = "SCANS" if ok else "FAILS"
            if not ok and not base_ok:
                verdict += "  (bare code fails too - harness limit)"
            elif ok and not base_ok:
                verdict += "  (scans where the bare code does not!)"
            print(f"  {label:<44} {verdict}")

    print("\n--- where is the actual boundary? ---")
    print("photographed small, by print size. The sheet prints 60 to an A4 page.")
    print("600px is roughly what a phone sees filling the frame with one label.\n")

    print(f"{'printed size':<34} {'EC H':<10} {'EC M':<10} {'EC Q'}")
    for px in (220, 200, 180, 170, 160, 150, 140, 130, 120, 110, 100, 90):
        row = f"{px}px wide ({px * 25.4 / 96:.0f}mm)"
        cells = []
        for ec in ("H", "M", "Q"):
            g, _ = matrix(url, ec, margin)
            img = rasterise(g, margin, scale, 0.20, True)
            cells.append("yes" if decode(cv2.resize(
                img, (px, px), interpolation=cv2.INTER_AREA)) == url else "NO")
        print(f"{row:<34} " + "".join(f"{c:<10}" for c in cells))

    print("\n--- M is not just weaker, it is erratic ---")
    print("30 readings per level at one size: does it decode reliably?\n")

    for ec in ("H", "Q", "M"):
        g, _ = matrix(url, ec, margin)
        img = rasterise(g, margin, scale, 0.20, True)
        small = cv2.resize(img, (140, 140), interpolation=cv2.INTER_AREA)

        hits = sum(1 for seed in range(30) if decode(degrade(small, noise=0.05, seed=seed)) == url)
        bar = "#" * hits + "." * (30 - hits)
        print(f"EC {ec}: {hits:>2}/30  {bar}")

    print("\nA level that reads 30/30 can be trusted on a sticker.")
    print(f"A level that reads {sum(1 for s in range(30) if decode(degrade(cv2.resize(rasterise(matrix(url, 'M', margin)[0], margin, scale, 0.20, True), (140, 140), interpolation=cv2.INTER_AREA), noise=0.05, seed=s)) == url)}/30 will produce labels that work in the")
    print("office and fail in the field, which is worse than a level that is")
    print("simply weak - the failure looks random and nobody can predict it.")

    return 0 if all(results) and all(fatal) else 1


if __name__ == "__main__":
    sys.exit(main())