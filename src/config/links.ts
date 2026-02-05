export const LINKS = {
	githubRepo: 'https://github.com/Open-Locker/Open-Locker',
	discordInvite: 'https://discord.gg/rZ74RYKN3H',
	partners: [
		{
			name: 'Smart City Hameln-Pyrmont',
			url: 'https://mitwirkportal.de/informieren/',
			logoSrc: '/ha-py-smart-city-logo (1).svg',
		},
		{
			name: 'Landkreis Hameln-Pyrmont',
			url: 'https://www.hameln-pyrmont.de/',
			logoSrc: '/logo_landkreis-hameln-pyrmont (1).png',
		},
		{
			name: 'Merona',
			url: 'https://merona.de/',
			logoSrc: '/merona-schriftzug-neu.svg',
		},
	],
	contactEmail: 'info@merona.de',
} as const;

export const DOCS_LINKS = {
	installation: `${LINKS.githubRepo}/blob/main/docs/Installation.md`,
	architecture: `${LINKS.githubRepo}/blob/main/docs/Architecture.md`,
} as const;

