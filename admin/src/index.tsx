import { createRoot, StrictMode } from '@wordpress/element';
import { HashRouter, Routes, Route, NavLink } from 'react-router-dom';
import { __ } from '@wordpress/i18n';

import ReviewQueue from './components/ReviewQueue';
import EvalDashboard from './components/EvalDashboard';
import VoiceProfile from './components/VoiceProfile';
import Settings from './components/Settings';
import './style.css';

function App() {
	return (
		<HashRouter>
			<div className="woocopy-app">
				<div className="woocopy-app__header">
					<h1>{ __( 'WooCopy AI', 'woocopy-ai' ) }</h1>
					<nav className="woocopy-app__nav">
						<NavLink to="/" end>
							{ __( 'Review Queue', 'woocopy-ai' ) }
						</NavLink>
						<NavLink to="/evals">{ __( 'Eval Dashboard', 'woocopy-ai' ) }</NavLink>
						<NavLink to="/voice">{ __( 'Voice Profile', 'woocopy-ai' ) }</NavLink>
						<NavLink to="/settings">{ __( 'Settings', 'woocopy-ai' ) }</NavLink>
					</nav>
				</div>

				{ ! window.woocopyAI.hasApiKey && (
					<div className="woocopy-app__notice">
						{ __(
							'No Anthropic API key configured yet. Add one in Settings to start generating copy.',
							'woocopy-ai'
						) }
					</div>
				) }

				<div className="woocopy-app__body">
					<Routes>
						<Route path="/" element={ <ReviewQueue /> } />
						<Route path="/evals" element={ <EvalDashboard /> } />
						<Route path="/voice" element={ <VoiceProfile /> } />
						<Route path="/settings" element={ <Settings /> } />
					</Routes>
				</div>
			</div>
		</HashRouter>
	);
}

const container = document.getElementById( 'woocopy-ai-root' );
if ( container ) {
	const root = createRoot( container );
	root.render(
		<StrictMode>
			<App />
		</StrictMode>
	);
}
