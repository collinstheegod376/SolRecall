import os
import re
import numpy as np
from PIL import Image
import vtracer
import xml.etree.ElementTree as ET

SOURCE_IMG = r"C:/Users/USER/.gemini/antigravity/brain/ddf19541-b974-4e72-9bbf-426108cfda94/.user_uploaded/media_1794246809110_069e45a1.png"
OUTPUT_DIR = r"c:\Users\USER\Documents\websites\undo\public\assets\img"
PUBLIC_DIR = r"c:\Users\USER\Documents\websites\undo\public"

os.makedirs(OUTPUT_DIR, exist_ok=True)

print("1. Loading source image...")
im = Image.open(SOURCE_IMG).convert("RGBA")
arr = np.array(im, dtype=np.float32)

# Gray / luminance channel (R=G=B)
gray = arr[:, :, 0]

# Floor black background noise (which is <= 2)
clean_lum = np.clip((gray - 2.0) / (255.0 - 2.0) * 255.0, 0.0, 255.0)
master_lum = np.round(clean_lum).astype(np.uint8)

# 1. Master 1024x1024 PNG (crisp white on pure black #000000)
print("2. Generating logo.png (1024x1024 master)...")
master_arr = np.zeros((1024, 1024, 4), dtype=np.uint8)
master_arr[:, :, 0] = master_lum
master_arr[:, :, 1] = master_lum
master_arr[:, :, 2] = master_lum
master_arr[:, :, 3] = 255
master_img = Image.fromarray(master_arr, "RGBA")
master_img.save(os.path.join(OUTPUT_DIR, "logo.png"), "PNG", optimize=True)

# 2. Transparent 1024x1024 PNG (pure white with luminance as alpha)
print("3. Generating logo-transparent.png (1024x1024 transparent)...")
trans_arr = np.zeros((1024, 1024, 4), dtype=np.uint8)
trans_arr[:, :, 0] = 255
trans_arr[:, :, 1] = 255
trans_arr[:, :, 2] = 255
trans_arr[:, :, 3] = master_lum
trans_img = Image.fromarray(trans_arr, "RGBA")
trans_img.save(os.path.join(OUTPUT_DIR, "logo-transparent.png"), "PNG", optimize=True)

# 3. Downsampled solid icons (PWA & iOS)
def create_solid_icon(size):
    return master_img.resize((size, size), Image.Resampling.LANCZOS)

# 4. Downsampled transparent icons (for anti-aliased favicon)
def create_trans_icon(size):
    alpha_ch = Image.fromarray(master_lum, "L").resize((size, size), Image.Resampling.LANCZOS)
    alpha_arr = np.array(alpha_ch)
    icon_arr = np.zeros((size, size, 4), dtype=np.uint8)
    icon_arr[:, :, 0] = 255
    icon_arr[:, :, 1] = 255
    icon_arr[:, :, 2] = 255
    icon_arr[:, :, 3] = alpha_arr
    return Image.fromarray(icon_arr, "RGBA")

print("4. Generating icon-512.png (512x512 PWA)...")
icon_512 = create_solid_icon(512)
icon_512.save(os.path.join(OUTPUT_DIR, "icon-512.png"), "PNG", optimize=True)

print("5. Generating icon-192.png (192x192 PWA)...")
icon_192 = create_solid_icon(192)
icon_192.save(os.path.join(OUTPUT_DIR, "icon-192.png"), "PNG", optimize=True)

print("6. Generating apple-touch-icon.png (180x180 iOS)...")
apple_icon = create_solid_icon(180)
apple_icon.save(os.path.join(OUTPUT_DIR, "apple-touch-icon.png"), "PNG", optimize=True)
apple_icon.save(os.path.join(PUBLIC_DIR, "apple-touch-icon.png"), "PNG", optimize=True)

print("7. Generating favicon-32.png (32x32)...")
fav_32 = create_solid_icon(32)
fav_32.save(os.path.join(OUTPUT_DIR, "favicon-32.png"), "PNG", optimize=True)
fav_32.save(os.path.join(PUBLIC_DIR, "favicon-32.png"), "PNG", optimize=True)

print("8. Generating multi-resolution public/favicon.ico (16, 32, 48)...")
master_img.save(
    os.path.join(PUBLIC_DIR, "favicon.ico"),
    format="ICO",
    sizes=[(16, 16), (32, 32), (48, 48)]
)
master_img.save(
    os.path.join(OUTPUT_DIR, "favicon.ico"),
    format="ICO",
    sizes=[(16, 16), (32, 32), (48, 48)]
)

# 9. High-precision vectorization to logo.svg (viewBox="0 0 512 512")
print("9. Generating logo.svg (512x512 vector)...")
# Upscale alpha 2x to 2048x2048 for subpixel bezier curve fitting
alpha_2k = Image.fromarray(master_lum, "L").resize((2048, 2048), Image.Resampling.LANCZOS)
alpha_2k_arr = np.array(alpha_2k)
inv_2k = ((alpha_2k_arr < 128).astype(np.uint8)) * 255
temp_png = os.path.join(PUBLIC_DIR, "temp_trace_2k.png")
temp_svg = os.path.join(PUBLIC_DIR, "temp_trace_2k.svg")
Image.fromarray(inv_2k).save(temp_png)

vtracer.convert_image_to_svg_py(
    temp_png,
    temp_svg,
    colormode="binary",
    mode="spline",
    filter_speckle=16,
    corner_threshold=60,
    length_threshold=4.0,
    max_iterations=10,
    splice_threshold=45,
    path_precision=3
)

tree = ET.parse(temp_svg)
root = tree.getroot()
path_elem = [e for e in root.iter() if e.tag.endswith("path")][0]
raw_d = path_elem.get("d")
transform_str = path_elem.get("transform", "")

# Extract translation values: e.g. translate(1440.35, 686.87)
tx, ty = 0.0, 0.0
m_trans = re.search(r"translate\(\s*([-+]?\d*\.?\d+)\s*,\s*([-+]?\d*\.?\d+)\s*\)", transform_str)
if m_trans:
    tx = float(m_trans.group(1))
    ty = float(m_trans.group(2))

# Scale from 2048x2048 to 512x512
scale = 512.0 / 2048.0

tokens = re.findall(r"[MZC]|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?", raw_d)
out_tokens = []
i = 0
while i < len(tokens):
    tok = tokens[i]
    if tok == "M":
        out_tokens.append("M")
        x = float(tokens[i+1])
        y = float(tokens[i+2])
        nx = (x + tx) * scale
        ny = (y + ty) * scale
        out_tokens.append(f"{nx:.2f}")
        out_tokens.append(f"{ny:.2f}")
        i += 3
    elif tok == "C":
        out_tokens.append("C")
        for k in range(3):
            x = float(tokens[i + 1 + 2*k])
            y = float(tokens[i + 2 + 2*k])
            nx = (x + tx) * scale
            ny = (y + ty) * scale
            out_tokens.append(f"{nx:.2f}")
            out_tokens.append(f"{ny:.2f}")
        i += 7
    elif tok == "Z":
        out_tokens.append("Z")
        i += 1

baked_d = " ".join(out_tokens)

svg_content = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" fill="none">
  <path fill-rule="evenodd" clip-rule="evenodd" d="{baked_d}" fill="#ffffff"/>
</svg>
"""

svg_path = os.path.join(OUTPUT_DIR, "logo.svg")
with open(svg_path, "w", encoding="utf-8") as f:
    f.write(svg_content)

# Clean up temp trace files
if os.path.exists(temp_png):
    os.remove(temp_png)
if os.path.exists(temp_svg):
    os.remove(temp_svg)

print("Brand suite generation completed successfully!")
