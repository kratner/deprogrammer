import React, { useState } from 'react';
import ACastAudio from './ACastAudio';
import YouTubeVideo from './YouTubeVideo';
import MediaRendererControl from './MediaRendererControl';

interface Media {
  mediaType: string;
  contentID: string;
  description?: string; // Optional description for each media element
  publishedDate?: string; //Optional published date for each media element
}

interface SelectableMediaRendererProps {
  mediaArray: Media[];
  selectedMediaIndex: number;
}

const SelectableMediaRenderer: React.FC<SelectableMediaRendererProps> = ({ mediaArray, selectedMediaIndex }) => {
    const currentMedia = mediaArray[selectedMediaIndex];
//   const [currentMediaIndex, setCurrentMediaIndex] = useState(0);
//   const currentMedia = mediaArray[currentMediaIndex];

  return (
    <div>

    {/* Control bar */}
    <MediaRendererControl
      currentMediaIndex={selectedMediaIndex}
      totalMediaCount={mediaArray.length}
      className='media-renderer-control'
    />
    <div className='current-media-information'>
        {/* Display optional description */}
        {currentMedia.description && <div className='description'><h2>{currentMedia.description}</h2></div>}
        {/* Display optional published date */}
        {currentMedia.publishedDate && <div className='publishedDate'><h3>{currentMedia.publishedDate}</h3></div>}
    </div>
      {currentMedia.mediaType === 'ACastAudio' ? (
        <ACastAudio contentID={currentMedia.contentID} />
      ) : currentMedia.mediaType === 'YouTubeVideo' ? (
        <YouTubeVideo contentID={currentMedia.contentID} />
      ) : null}
    </div>
  );
};

export default SelectableMediaRenderer;
