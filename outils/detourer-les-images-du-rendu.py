# -*- coding: utf-8 -*-
"""
Détoure les objets du rendu de la maquette.

**Pourquoi découper dans une capture plutôt que prendre les fichiers.** Le fichier de
maquette ne contient que deux images : le rendu 3D du logo, posé sur fond noir, et un petit
logo secondaire. Les quatre outils qu'on voit sur le rendu — la bougie, la clé plate, la
voiture et le trousseau — n'y sont nulle part : ils font partie d'un fond composé dont on
n'a que l'aperçu. Les redessiner en SVG a été essayé : ce n'étaient pas les bons, et le
propriétaire l'a dit. Ce sont donc ceux-là qu'il faut, et la capture est la seule source.

**Pourquoi un remplissage depuis les bords, et non un seuil.** Le fond n'est pas uni : il
porte un quadrillage, et deux halos de couleur dans les coins. Un seuil de clarté garderait
le halo rose et mangerait le chrome de la clé, qui est presque aussi clair que le papier. Le
remplissage part des bords et n'avance que vers un voisin de couleur *proche* : il traverse
donc les dégradés et le quadrillage, qui varient doucement, et s'arrête net au bord d'un
objet, qui est une rupture. Une garde l'empêche de fuir dans les zones sombres.

**L'ombre portée est perdue, et c'est voulu** : elle se retrouve en CSS
(`filter: drop-shadow(...)`), comme dans la maquette, ce qui la garde nette à toute taille.
"""
import os
from collections import deque

from PIL import Image, ImageFilter

# Le rendu de la maquette, tel que le propriétaire l'a transmis le 29/09. Il n'est pas
# versionné — 2 560 × 1 439 pour un seul usage — et se redemande au besoin : ce script
# n'existe que pour refaire les découpes si l'on change de rendu.
SRC = 'C:/chemin/vers/le-rendu-de-la-maquette.jpg'
DST = 'C:/laragon/www/GESTION-DE-SITES/public/logos/'


def detourer(im, pas=6, plancher=150):
    """Rend une image RGBA dont le fond, atteint depuis les bords, est transparent.

    `pas` : l'écart maximal admis entre un pixel du fond et son voisin — **six niveaux**, et
    c'est une correction. À quatorze, la clé plate et la bougie disparaissaient : leur chrome
    est presque aussi clair que le papier, et son bord adouci offrait au remplissage un
    escalier de marches assez basses pour y entrer. Les dégradés du fond, eux, varient de
    moins d'un niveau par pixel — six suffit largement à les traverser, et le quadrillage,
    qui n'est qu'à cinq niveaux du blanc, passe encore.

    `plancher` : en dessous de cette clarté, on n'avance plus. Sans cette garde, une ombre
    douce servirait de passage vers l'intérieur d'un objet.
    """
    l, h = im.size
    pixels = im.load()

    fond = bytearray(l * h)
    file = deque()

    def semer(x, y):
        if fond[y * l + x]:
            return
        r, v, b = pixels[x, y]
        if min(r, v, b) < plancher:
            return
        fond[y * l + x] = 1
        file.append((x, y))

    for x in range(l):
        semer(x, 0)
        semer(x, h - 1)
    for y in range(h):
        semer(0, y)
        semer(l - 1, y)

    while file:
        x, y = file.popleft()
        r, v, b = pixels[x, y]

        for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            nx, ny = x + dx, y + dy

            if nx < 0 or ny < 0 or nx >= l or ny >= h or fond[ny * l + nx]:
                continue

            r2, v2, b2 = pixels[nx, ny]

            if min(r2, v2, b2) < plancher:
                continue

            if abs(r2 - r) <= pas and abs(v2 - v) <= pas and abs(b2 - b) <= pas:
                fond[ny * l + nx] = 1
                file.append((nx, ny))

    alpha = Image.frombytes('L', (l, h), bytes(255 - 255 * o for o in fond))
    # Un bord net laisse des marches d'escalier ; un flou d'un demi-pixel les efface, et la
    # frange claire qu'il laisse est invisible sur un papier de la même clarté.
    alpha = alpha.filter(ImageFilter.GaussianBlur(0.7))

    sortie = im.convert('RGBA')
    sortie.putalpha(alpha)

    return sortie


def rogner(im):
    """Ramène l'image à ce qui reste visible : une marge transparente pèse pour rien."""
    boite = im.getchannel('A').point(lambda v: 255 if v > 8 else 0).getbbox()

    return im.crop(boite) if boite else im


source = Image.open(SRC).convert('RGB')
print('source', source.size)

PIECES = {
    # La bougie, le logo et la clé forment un seul groupe composé : ils se recouvrent, et
    # les séparer obligerait à replacer à la main ce que le rendu a déjà composé.
    'atelier-logo.png': ((100, 620, 1260, 1439), 900),
    'atelier-voiture.png': ((0, 0, 260, 200), 260),
    'atelier-cles.png': ((2380, 1210, 2560, 1439), 200),
}

for nom, (boite, largeur) in PIECES.items():
    piece = rogner(detourer(source.crop(boite)))

    if piece.width > largeur:
        hauteur = round(piece.height * largeur / piece.width)
        piece = piece.resize((largeur, hauteur), Image.LANCZOS)

    # **256 couleurs plutôt que seize millions, et l'écart ne se voit pas.** Mesuré sur le
    # logo : 530 Ko contre 94 Ko, comparés côte à côte sur le papier de la page. C'est la
    # première image de la première page, souvent sur un réseau lent — et un rendu 3D pose
    # des dégradés doux, que le tramage suffit à tenir. La transparence est conservée.
    piece = piece.quantize(colors=256, method=Image.FASTOCTREE, dither=Image.FLOYDSTEINBERG)

    piece.save(DST + nom, optimize=True)
    print(nom, piece.size, os.path.getsize(DST + nom) // 1024, 'Ko')
