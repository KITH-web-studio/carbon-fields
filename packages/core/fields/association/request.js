import apiFetch from '../../utils/api-fetch';
import { dependencyRequest, editorObjectId } from '../../utils/post-choice-context';

export function associationRequest( { containerId, hierarchyResolver, value, field, optionsDependencyValue }, search ) {
	const dependency = dependencyRequest( field, optionsDependencyValue );
	return apiFetch(
		window.wpApiSettings.root + 'carbon-fields/v1/association/' + ( search ? 'options' : '' ),
		'get',
		{
			container_id: containerId,
			field_id: hierarchyResolver,
			object_id: editorObjectId(),
			...( dependency && { dependency } ),
			options: value.map( ( item ) => [ item.id, item.type, item.subtype ].join( ':' ) ).join( ';' ),
			...search
		}
	);
}
