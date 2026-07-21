export const LINKS = {
	githubRepo: 'https://github.com/Open-Locker/Open-Locker',
	discordInvite: 'https://discord.gg/rZ74RYKN3H',
	funders: [
		{
			name: 'BMWSB',
			url: 'https://www.bmwsb.bund.de/',
			logoSrc: '/BMWSB Logo.png',
		},
		{
			name: 'KfW',
			url: 'https://www.kfw.de/',
			logoSrc: '/KfW_Logo.JPG',
		},
		{
			name: 'Landkreis Hameln-Pyrmont',
			url: 'https://www.hameln-pyrmont.de/',
			logoSrc: '/Landkreis_Logo.gif',
		},
	],
	partners: [
		{
			name: 'Smart City Hameln-Pyrmont',
			url: 'https://mitwirkportal.de/informieren/',
			logoSrc: '/Ha-Py-Smart City-Landkreis Hameln-Pyrmont-rgb (4).jpg',
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

