// Abstandshalter für Verschluss zu Electric Solenoid Lock FIT0620

use <fillets3d.scad>;   // https://github.com/ademuri/openscad-fillets

// -----------------------------
// Parameter
// -----------------------------

// Abmessungen Verschluss
spacer_len      = 38;       // Länge Verschlussplatte
spacer_width    = 21;       // Breite Verschlussplatte
spacer_height   = 20;       // Höhe des Spacers

hole_diameter   = 4.2;      // Durchmesser Schraubenloch
hole2_diameter  = 6.5;      // Durchmesser Gewinde Nietmutter
hole2_height    = 9;        // Höhe Gewinde Nietmutter
hole3_diameter  = 10;       // Durchmesser Kragen Nietmutter
hole3_height    = .5;       // Höhe Kragen Nietmutter

hole_offset_x      = 25;   // Abstand Bohrungen

// -----------------------------
// 📦 Hauptmodul
// -----------------------------
module spacer() {
 $fn=50;

// use the following line to smoothen. caution! will take some time to render!
topBottomFillet(b = 0, t = spacer_height, r = 1, s = 10, e=1)

    difference(){
        cube([spacer_len, spacer_width, spacer_height], center=false);
        
        translate([spacer_len / 2 - hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(spacer_height+2, d = hole_diameter);
        translate([spacer_len / 2 - hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(hole2_height+1, d = hole2_diameter);
        translate([spacer_len / 2 - hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(hole3_height+1, d = hole3_diameter);
        
        translate([spacer_len / 2 + hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(spacer_height+2, d = hole_diameter);
        translate([spacer_len / 2 + hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(hole2_height+1, d = hole2_diameter);
        translate([spacer_len / 2 + hole_offset_x / 2, spacer_width / 2, -1])
        cylinder(hole3_height+1, d = hole3_diameter);
        }
}

// -----------------------------
// ▶️ Ausgabe
// -----------------------------
spacer();