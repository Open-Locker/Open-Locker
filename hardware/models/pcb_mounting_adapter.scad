$fn=128;

height=40;
width=70;
wall_thickness=2;
inner_radius=2.3;
outer_radius=4.5;

difference() {
    linear_extrude(height){
        translate([width / 2, 0, 0]) circle(outer_radius);
        translate([-width / 2, 0, 0]) circle(outer_radius);

        hull(){
            translate([width / 2, 0, 0]) circle(wall_thickness);
            translate([-width / 2, 0, 0]) circle(wall_thickness);
        }
    }

    linear_extrude(height){        
        translate([width / 2, 0, 0]) circle(inner_radius);
        translate([-width / 2, 0, 0]) circle(inner_radius);
    }
}