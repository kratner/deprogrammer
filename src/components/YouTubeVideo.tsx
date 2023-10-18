import React from "react";

interface YouTubeVideoProps {
  contentID: string;
  description: string;
}

const YouTubeVideo: React.FC<YouTubeVideoProps> = ({
  contentID,
  description,
}) => {
  return (
    <div className="video-container">
      <div className="video-wrapper">
        <iframe
          title="YouTube Video"
          src={`https://www.youtube.com/embed/${contentID}`}
          frameBorder="0"
          allowFullScreen
        ></iframe>
      </div>
    </div>
  );
};

export default YouTubeVideo;
