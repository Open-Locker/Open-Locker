import { defineCollection } from "astro:content";
import { z } from "astro/zod";

const blog = defineCollection({
	type: "content",
	schema: z.object({
		title: z.string(),
		description: z.string(),
		pubDate: z.coerce.date(),
		updatedDate: z.coerce.date().optional(),
		draft: z.boolean().optional().default(false),
	}),
});

export const collections = { blog };
